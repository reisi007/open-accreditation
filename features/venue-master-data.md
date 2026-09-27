# Venue-Master-Data (W12)

Spielstätten / Austragungsorte als **mandant-weite Stammdaten**. Eine Tabelle,
referenziert von **Teams und Events** — beide über `venue_id`. Ersetzt die
zwei Freitext-Spalten `teams.home_venue` und `events.venue`.

Entscheidung vom 2026-09-27 (user-bestätigt),-Präzedenz für alle
Detailfragen: **Kategorien** (`categories.manage` = Kopier-Permission,
`mandant_admin`; `CategoryController` + `CategoryResource` als Form).

## Die vier Entscheidungen

### 1. Eine Tabelle, mandant-scoped, von Teams **und** Events referenziert

`venues` liegt auf Mandant-Ebene — es gibt **keine** Team-Ebene. Beide
Referenten teilen dieselbe Zeile:

| Referent | Spalte | Fachliche Bedeutung |
|---|---|---|
| `teams` | `venue_id` (nullable FK, `restrict`) | Heimstätte des Vereins — der Default-Ort |
| `events` | `venue_id` (nullable FK, `restrict`) | Ort des konkreten Spiels — **überschreibt** den Default des Vereins |

Die Fallback-Kette bleibt unverändert und löst jetzt über die Relationen auf:

```
venue_effective = events.venue?->name ?? teams.venue?->name
```

(P3a-Detail-Route `GET /api/portal/events/{id}`, `PortalEventDetailResource`.)

**Warum keine zweite Tabelle und kein `venue_id` nur am Team:** Ein Austragungsort
ist eine Eigenschaft des *Orts*, nicht des Vereins. Zwei Vereine desselben
Verbands im selben Stadion, oder ein Auswärtsspiel im Stadion des Gegners,
wären mit Freitext/Team-only-Ansatz zwei bis drei unabhängige Zeilen mit
derselben fachlichen Aussage — genau die Dublette, die diese Entscheidung
auflöst. Der Preis ist explizit: der Verband pflegt die Liste, einzelne
Vereine können sie nicht selbst anlegen (siehe §5).

### 2. Die Freitext-Spalten sind **ersetzt**, nicht ergänzt

`teams.home_venue` und `events.venue` sind **gedroppt**. Es gibt bewusst
**keine** Parallelhaltung: eine zweite Quelle der Wahrheit für denselben
Sachverhalt wäre der Ausgangszustand, den W12 abschafft. Konsequenzen:

- **Kein Backfill.** Es gibt keine Produktionsdaten (Go-Live geparkt). Alle
  Zeilen, die vorher einen Freitext hatten, haben jetzt `venue_id = null` —
  semantisch exakt „kein Ort konfiguriert", wie vorher.
- **`TeamResource` / `EventResource`** liefern `venue_id` plus das aufgelöste
  Nested-Objekt `venue: {id, name} | null` (Muster wie das bestehende
  `team: {id, name}`).
- **Portal-Payload bleibt zeichengleich:** `GET /api/portal/overview` →
  `teams[].home_venue` und `GET /api/portal/events` → `venue` /
  `venue_effective` sind weiterhin **Strings** — jetzt aber der *aufgelöste*
  Name aus den Stammdaten. Der öffentliche Vertrag bricht damit nicht.
- `POST`/`PUT` auf Team und Event akzeptieren `venue_id` (nullable `integer`).
  Ein `venue_id` eines fremden Mandanten ist **404** (nicht 403), gespiegelt
  am Event-Type-Guard (`assertEventTypeOfMandant`).

### 3. Deaktivieren statt Löschen (solange referenziert)

`is_active` (bool, default `true`) ist der Lebenszyklus-Flag, **kein**
Soft-Delete. Ein referenzierter Ort wird deaktiviert, nie gelöscht:

| Aktion | Ergebnis |
|---|---|
| `PUT {is_active: false}` | `200` — Zeile bleibt, `teams_count`/`events_count` unverändert |
| `PUT {is_active: true}` | `200` — **Reaktivierung** derselben Zeile (nie ein Duplikat) |
| `DELETE` ohne Referenz | `204` — Fluchtweg für einen Tippfehler-Eintrag |
| `DELETE` mit Referenz | `409` + deutsche Message mit **beiden** Zählern |

Ein deaktivierter Ort **rendert weiterhin seinen Namen** im Portal. Das ist
Absicht: `is_active` stoppt *neue Zuweisungen*, es löscht keine Historie. Ein
`venue_effective` darf nicht still leer werden, nur weil ein Verband eine
Spielstätte aus dem Umlauf nimmt.

### 4. Unique auf `(mandant_id, name)` — ohne Partial Index

`unique(['mandant_id', 'name'])`, bewusst **kein** partieller/bedingter Index
über nur aktive Zeilen:

- **Ein deaktivierter Name bleibt belegt.** Der Datensatz wird reaktiviert,
  nicht dupliziert. Sonst hätte ein Verband nach „Zeppelin Arena
  deaktivieren" zwei Zeilen mit demselben Namen im Bestand — und die
  historischen Referenzen der einen Zeile wären nicht mehr von der neuen zu
  unterscheiden.
- **Portabilität (§2).** Ein partieller Index ist kein ANSI-SQL und müsste
  über einen Service-Ausweg plus separaten Integrationstest gelöst werden. Er
  kauft hier nichts: die Tabelle ist pro Verband klein (Spielstätten, keine
  Transaktionen).
- Zwei Verbände dürfen denselben Namen führen — die Uniqueness ist
  mandant-scoped.

Der `Rule::unique('venues','name')->where('mandant_id', …)` im Controller
spiegelt das, damit ein Duplikat **422 mit deutscher Message** liefert und
niemals ein roher DB-Constraint-Fehler.

## Schema

```sql
CREATE TABLE venues (
  id          bigserial PRIMARY KEY,
  mandant_id  bigint NOT NULL REFERENCES mandants(id) ON DELETE CASCADE,
  name        varchar(255) NOT NULL,
  is_active   boolean NOT NULL DEFAULT true,
  created_at  timestamp NULL, updated_at timestamp NULL,
  UNIQUE (mandant_id, name)          -- venues_mandant_id_name_unique
);
CREATE INDEX venues_mandant_id_index ON venues (mandant_id);

-- in teams und events identisch:
ALTER TABLE teams  ADD venue_id bigint NULL REFERENCES venues(id) ON DELETE RESTRICT;
ALTER TABLE events ADD venue_id bigint NULL REFERENCES venues(id) ON DELETE RESTRICT;
-- teams.home_venue  bzw.  events.venue  → DROP COLUMN
```

`ON DELETE RESTRICT` auf **beiden** FKs: „deaktivieren statt löschen" ist die
*Policy*, der FK ist die *Durchsetzung*. Auch wenn die API einen referenzierten
Ort nie löscht (409), muss ein direktes `DELETE` (Konsole, Tinker, ein
künftiger Codepfad, der den Guard vergisst) die Daten nicht still verlieren
oder auf `NULL` setzen. Eine `Venue` stirbt ausschließlich mit ihrem Mandant
(`CASCADE`).

## API-Vertrag

```
GET    /api/admin/venues          index    can:venues.manage
POST   /api/admin/venues          store    can:venues.manage  + throttle:admin
PUT    /api/admin/venues/{venue}  update   can:venues.manage  + throttle:admin
DELETE /api/admin/venues/{venue}  destroy  can:venues.manage  + throttle:admin
```

Index und alle Writes sind mandant-scoped (`forMandant()` /
`MandantContext`); `{venue}` läuft über den mandant-scoped
Route-Model-Binding, ein fremder `venue_id` ist deshalb **404**, nie 403.

`VenueResource`:

```json
{ "id": 1, "name": "Zeppelin Arena", "is_active": true,
  "teams_count": 2, "events_count": 1,
  "created_at": "…", "updated_at": "…" }
```

`teams_count` / `events_count` sind die Zahlen, aus denen der 409-Guard
arbeitet — der Admin sieht vor dem Klick, was ein Löschen zerstören würde.

**Validierung** (spiegelt `CategoryController::rules()` exakt):
`name` = `required` (create) / `sometimes` (update) + `string` + `max:255` +
`ValidUtf8` + mandant-scoped `Unique` (ohne `ignore()` des eigenen Id beim
Create, mit beim Update). `is_active` = `sometimes|boolean`.

Der 409-Body ist `{"message": "…"}` mit deutscher Message, die beide Zähler
nennt, z. B. `Spielstätte wird noch von 2 Vereinen und 1 Event verwendet und
kann nicht gelöscht werden. Bitte stattdessen deaktivieren.`

## Berechtigungen

`venues.manage` ist in `config/permissions.php` an **mandant_admin UND
team_admin** vergeben — exakt dieselbe Rolle wie bei `categories.manage`, und
aus demselben Grund:

- Eine Venue-Zeile ist **nie** team-eigen. Es gibt keine Team-Ebene, also ist
  die Fläche **mandant-scoped**, nicht team-scoped — der Team-Admin schreibt in
  die Master-Liste *seines* Verbands, nicht in die eines fremden.
- Der Grant ist trotzdem nötig, weil die Venue **Vorbedingung** der Formulare
  ist, die der `team_admin` bereits bearbeiten darf: `teams.manage` und
  `events.manage`. Die Venue-Combobox im Team-Formular **und** im Event-Formular
  liest `GET /api/admin/venues` und bietet Inline-Create („Ort kann auch GUI
  mäßig mit erstellt werden"). Ohne den Grant liefert der Picker 403 (leere
  Combobox) und die Create-Affordance 403t — der `team_admin` dürfte einen Ort
  zuweisen, aber nie einen anlegen. Genau das ist der Grund, aus dem der
  `team_admin` `categories.manage` für den Kategorie-Picker derselben Formulare
  hält.
- Der `team_admin` braucht dafür **keine** Team-Zuweisung-spezifische
  Venue-Schranke: das Gate läuft ohne `team_id`-Argument, also mandant-weit.
  Eine `team_admin`-Rolle ganz **ohne** Team-Zuweisung bleibt trotzdem abgelehnt
  (generische Gate-Semantik, `RolePermissionTest`).

`user` und `verifier` halten die Permission nicht (Route-Gate 403). Die
Mandanten-Isolation ist von dem Grant unberührt: `MandantContext` + mandant-
scoped Route-Binding bleiben die Grenze, ein fremder `venue_id` ist 404.

## Erster Datensatz

Es gibt **keinen** Seeder für Venues. Der erste Ort entsteht über die
`POST`-Route durch den Admin (Inline-Create im Team-Formular, Frontend). Bewusst
freigelassen: ein Seed-Datensatz würde in Dev/CI eine fachliche Aussage
erfinden, die niemand gepflegt hat.

## Tests

`backend/tests/Feature/VenueTest.php` pinnt alle vier Entscheidungen:
CRUD, Duplikat → 422, deaktivieren/reaktivieren, DELETE ohne Referenz → 204,
DELETE mit Team- und/oder Event-Referenz → 409 mit Zählern, Cross-Mandant-404
(Rezept-Spiegel, `venue_id` am Team/Event, Fremd-Name reserviert nichts),
Validierungsfehler, der `restrict`-FK auf DB-Ebene, der Rollen-Gate
(mandant_admin/super_admin/team_admin ja; user/verifier nein — inkl.
Negativkontrollen: `team_admin` **ohne** Team-Zuweisung = 403, `team_admin`
eines fremden Mandants = 403, `team_admin` auf fremde Venue = 404) sowie die
Fabriken.

Geänderte Alt-Tests: `AdminTeamTest`, `AdminEventTest`, `PortalTest`,
`RolePermissionTest` (Matrix um `venues.manage` erweitert, `team_admin`-Zeile
inklusive).

## Portabilität (§2)

Ausschließlich portable Konstrukte: `foreignId()->index()->constrained()`,
`unique()`, `boolean()->default(true)`, `dropColumn` (SQLite ≥ 3.35, CI 3.45.2).
Kein PG-spezifisches SQL, kein `jsonb`, kein partieller Index, keine
Datumsarithmetik.
