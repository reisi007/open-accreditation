# Venue-Master-Data (W12)

Spielstätten / Austragungsorte als **mandant-weite Stammdaten**. Eine Tabelle,
referenziert von **Teams und Events** — beide über `venue_id`. Ersetzt die
zwei Freitext-Spalten `teams.home_venue` und `events.venue`.

Entscheidung vom 2026-09-27 (user-bestätigt), Präzedenz für alle
Detailfragen: **Kategorien** (`categories.manage` = Kopier-Permission,
`mandant_admin`; `CategoryController` + `CategoryResource` als Form) — für
Flächen-Form, Rollenliste und Lese-Umfang. Für das **Besitz-Prädikat auf dem
Schreibweg** ist die Vorlage nur zur Hälfte kopierbar, siehe §5.

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
Mandant-skaliert heißt aber nicht team-skaliert: auf `update` / `destroy` kommt
eine Team-Achse **obendrauf**, die erst nach der Mandanten-Achse geprüft wird
und **403** antwortet (§5).

### Zusätzlich: die **mandant-adressierte** Fläche (Board 7b)

```
GET    /api/admin/mandants/{mandant}/venues            indexForMandant
POST   /api/admin/mandants/{mandant}/venues            storeForMandant     + throttle:admin
PUT    /api/admin/mandants/{mandant}/venues/{venue}    updateForMandant    + throttle:admin
DELETE /api/admin/mandants/{mandant}/venues/{venue}    destroyForMandant   + throttle:admin
```

Dieselbe Permission (`venues.manage`), **anderer Scope**. Die host-skalierten
Routen bleiben unangetastet — sie sind die richtige Wahl für jede Seite, die
*auf* einer Verbands-Domain lebt (Kategorien, Events, Akkreditierungen,
`VenuesPage`).

**Warum es sie braucht:** `/admin/mandants/{id}` ist die einzige Admin-Seite,
die einen Mandanten **explizit per URL adressiert**. Ihre Team-Liste lief über
`{mandant}`, die Venue-Combobox darüber aber host-skaliert — der Picker bot
deshalb die Orte des **Host**-Mandanten an, und der Inline-Create schrieb die
Zeile **ohne jede Fehlermeldung** in den Host-Mandanten. Erst der anschließende
Team-Save scheiterte mit 404 (`TeamController::assertVenueOfMandant`). Ein
stiller Schreibvorgang in einen fremden Mandanten ist der Defekt; die
Korrektheit ist unter jeder Ausgestaltung der Admin-Listen identisch, deshalb
ist sie hier festgehalten.

**Zugriffsregel** (`ResolvesMandantRouteParameter`):

| Aufrufer | Adressierbarer Mandant |
|---|---|
| `super_admin` | **jeder**, von jedem Host — der Early-Return für ihn ist sein Zweck |
| alle anderen | nur der aktuelle (`MandantContext`), sonst **404** |

Die Fläche verschafft damit **niemandem** neuen Zugriff: ein `mandant_admin` /
`team_admin` erhält exakt die Venues, die ihm die host-skalierten Routen schon
geben — und er schreibt auf dieser Fläche auch nicht weiter als dort (§5).
`{venue}` wird über `$mandant->venues()->findOrFail()` aufgelöst, ein
fremder `venue_id` also konstruktionsbedingt 404. `mandant_id` beim Create kommt
aus der **Route**, nie aus dem Payload; der mandant-scoped `Rule::unique` liest
dieselbe Id.

Bewusst **kein** `super_admin`-Zwang wie beim Team-CRUD: die Combobox samt
Inline-Create ist für `mandant_admin` und `team_admin` Teil der Formulare, die
sie bereits bearbeiten dürfen.

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
  die Fläche **mandant-scoped**, nicht team-scoped — der `team_admin` schreibt
  in die Master-Liste *seines* Verbands, nicht in die eines fremden. *Wie weit*
  er darin schreiben darf, ist davon getrennt geregelt — siehe unten.
- Der Grant ist trotzdem nötig, weil die Venue **Vorbedingung** der Formulare
  ist, die der `team_admin` bereits bearbeiten darf: `teams.manage` und
  `events.manage`. Die Venue-Combobox im Team-Formular **und** im Event-Formular
  liest `GET /api/admin/venues` und bietet Inline-Create („Ort kann auch GUI
  mäßig mit erstellt werden"). Ohne den Grant liefert der Picker 403 (leere
  Combobox) und die Create-Affordance 403t — der `team_admin` dürfte einen Ort
  zuweisen, aber nie einen anlegen. Genau das ist der Grund, aus dem der
  `team_admin` `categories.manage` für den Kategorie-Picker derselben Formulare
  hält.
- Das **Gate** selbst kennt keine `team_id`: es prüft `venues.manage` und läuft
  damit mandant-weit. Auf dem **Schreibweg** gibt es sehr wohl eine
  Team-Zuweisungs-Schranke — sie sitzt im Controller, nicht im Gate (siehe
  unten). Eine `team_admin`-Rolle ganz **ohne** Team-Zuweisung fällt deshalb
  schon beim Ermitteln der Team-Menge durch (`teamIds()`, `VenueTest`).

`user` und `verifier` halten die Permission nicht (Route-Gate 403). Die
Mandanten-Isolation ist von dem Grant unberührt: `MandantContext` + mandant-
scoped Route-Binding bleiben die Grenze, ein fremder `venue_id` ist 404.

### Schreibbreite des `team_admin`: nur Orte, die sein eigenes Team benutzt

Der Grant gilt für den `team_admin` beim **Lesen und Anlegen mandantweit**, beim
**Ändern nur teambezogen**. `PUT` und `DELETE` auf einen Ort, den keines der
Teams des Aufrufers benutzt, sind **403**:

| Fläche | `team_admin` | `mandant_admin`, `super_admin` |
|---|---|---|
| `index` | mandantweit | mandantweit |
| `store` | mandantweit | mandantweit |
| `update` / `destroy` | **nur Orte, die ein eigenes Team benutzt** | mandantweit |

`mandant_admin` und `super_admin` sind unberührt: für sie ist die Team-Menge
leer, der Guard ist ein No-op. Die Grenze gilt für `PUT` **und** `DELETE`, also
auch für das Umbenennen, das Deaktivieren/Reaktivieren und das Löschen.

**Warum Lesen und Anlegen mandantweit bleiben.** Der bestätigte Inline-Create im
Team-Formular würde sonst dead-enden — ein Verband mit null Orten käme gar erst
nicht an das Formular, in dem der erste Ort entsteht. Und `venues.manage` folgt
`categories.manage` **zeilengleich**: derselbe Picker, dieselben Formulare,
dieselbe Rollenliste.

**Warum Ändern getrennt davon ist.** Umbenennen und (De)aktivieren/Löschen sind
keine Eingabe in ein eigenes Formular, sondern eine Korrektur an geteilten
Stammdaten. Ein Verein, der den Ort des Nachbarvereins umbenennt, ärgert den
Nachbarn, ohne ihn zu fragen. Deaktivieren ist erst recht nicht folgenlos: weil
`unique(mandant_id, name)` bewusst **nicht** über `is_active` filtert
(Entscheidung 4), nimmt ein deaktivierter Name den Namen **dauerhaft** aus dem
Umlauf. Ein unreferenzierter Ort wird deshalb nicht als „harmlos"
durchgelassen — die Macht, Namen mandantweit zu besetzen, bleibt auf
Mandanten-Ebene. Dasselbe gilt für einen Ort, den nur ein **Event** benutzt: eine
Spielzuweisung ist keine Eigentumsanzeige.

**Warum das Prädikat nicht dem der Kategorien entspricht.** `assertOwnership()`
(`Concerns/ResolvesAdminTeamScope.php:161`) liest
`$model->getAttribute('team_id')`. **`Venue` hat keine `team_id`-Spalte**
(`#[Fillable(['mandant_id', 'name', 'is_active'])`, `Venue.php:33`) — die
Tabelle kennt bewusst keine Team-Ebene. Das Prädikat hätte `null` geliefert und
**jeden** Schreibversuch verweigert, auch den auf einem Ort, den der Aufrufer
wirklich benutzt. Die Form ist also übernommen, das Prädikat ist ein eigenes
(`VenueController::assertWritableBy()`, `VenueController.php:269`): leeres
`teamIds()` bedeutet unbeschränkt, die Mandanten-Achse ist vorher geklärt, und
„mein Ort" ist die **Schnittmenge** der Team-Menge des Ortes mit den Teams des
Aufrufers. Gezählt wird dabei ausschließlich `teams.venue_id` — die stehende
Heimstätte eines Vereins. `events.venue_id` ist die Zuweisung eines einzelnen
Spiels und gibt ihrem Admin kein Umbenennungs- oder Deaktivierungsrecht auf die
Stätte des ganzen Verbands.

**`index` ist bewusst nicht wie bei Kategorien gefiltert.** Kategorien haben
eine echte Mandant-/Team-Trennung, Venues nicht; der `team_admin` liest die Liste
seines **ganzen** Verbands, weil der Picker auch das Derby-Stadion des Nachbarn
anbieten muss. Der Guard gilt ausschließlich auf den schreibenden Wegen.

**Beide Flächen durch Konstruktion, nicht durch Ablesen.** Der Guard sitzt in
den geteilten Workern `updateVenue` / `deleteVenue`, an die **alle vier**
schreibenden Actions delegieren (host-skaliert **und** mandant-adressiert). Ein
Guard pro Action könnte zwischen den Flächen auseinanderlaufen, einer im Worker
nicht.

**Fehlercodierung: 403 auf der Team-Achse, 404 auf der Mandanten-Achse.** Die
Mandanten-Achse ist vorher geklärt und antwortet 404 (Binding-Scope
`assertMandantScope()` bzw. `findOrFail()` über `$mandant->venues()`). 404 wäre
hier zusätzlich eine **Lüge**: der Aufrufer sieht den Ort im mandantweiten Index
— das ist der Zweck des breiten Lesens. 403 ist der ehrliche Code und der
konsistente, weil die Codebase 404 der Mandanten-Achse und 403 der Rollen-/Team-
Achse vorbehält.

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

Dieselbe Datei pinnt die **Schreibbreite des `team_admin`** (§5) in beide
Richtungen: Lesen und Anlegen mandantweit (derselbe Nutzer, `index` 200 /
`store` 201), Umbenennen und Deaktivieren des Ortes, den ein eigenes Team
benutzt, erlaubt; 403 dagegen bei einem Ort, den nur ein Nachbarverein, den
**niemand**, oder nur ein Event benutzt. Eigener Test dafür, dass der 403 der
Schreibbreite und **nicht** das `venues.manage`-Gate ist (derselbe Nutzer
passiert das Gate) — sonst wäre ein verweigertes Grant von einer Enge nicht zu
unterscheiden. Die Mandanten-Ebene behält die breite Macht: `mandant_admin`
benennt und löscht weiter einen unbenutzten Ort. Der 409-Pfad bleibt für den
`team_admin` erreichbar — auf einem Ort, den sein eigenes Team noch
referenziert; dort wird auch die kombinierte Meldung „1 Verein und 1 Event"
erstmals für diese Rolle erreichbar.

Geänderte Alt-Tests: `AdminTeamTest`, `AdminEventTest`, `PortalTest`,
`RolePermissionTest` (Matrix um `venues.manage` erweitert, `team_admin`-Zeile
inklusive).

`backend/tests/Feature/AdminMandantVenueTest.php` pinnt die mandant-adressierte
Fläche (Board 7b), jeweils mit **Host-Mandant ≠ URL-Mandant**:
Liste/Inline-Create landen im adressierten Mandanten (und der Folge-Team-Save
404 nicht mehr), `super_admin` darf über jeden Host jeden Mandanten,
`mandant_admin`/`team_admin` erreichen nur den eigenen (fremd → 404, ohne
Namenleck), ein `venue_id`/`{venue}` eines fremden Mandanten ist 404 auf
Update/Delete, die Namens-Uniqueness folgt der **Route**-Mandanten-Id, und die
host-skalierten Routen antworten weiterhin ausschließlich mit dem Host-Mandanten.
Die Schreibbreite des `team_admin` gilt hier **genauso** wie auf der
host-skalierten Fläche — eigener Ort umbenennen ja, Nachbarverein-Nein — weil
beide Actions an denselben Worker delegieren.

## Portabilität (§2)

Ausschließlich portable Konstrukte: `foreignId()->index()->constrained()`,
`unique()`, `boolean()->default(true)`, `dropColumn` (SQLite ≥ 3.35, CI 3.45.2).
Kein PG-spezifisches SQL, kein `jsonb`, kein partieller Index, keine
Datumsarithmetik. Die mandant-adressierte Fläche ändert daran nichts: sie
skaliert über `Venue::forMandant()` bzw. `$mandant->venues()` — dieselben
portablen Query-Builder-Pfade wie die host-skalierten Routen. Auch die
Team-Achse des Schreib-Guards ist eine gewöhnliche `exists()`-Abfrage auf
`Team::forMandant()->whereIn('id', …)->where('venue_id', …)` und damit auf
beiden Engines gleich. Gate: dieselbe Suite auf SQLite `:memory:` **und** auf
echtem PostgreSQL 17 (`bash scripts/test-pgsql.sh`), beide grün — zuletzt
aus CI-Lauf `37280377310` gelesen (2026-10-05, nicht selbst gefahren):
SQLite **1983 passed** (12501 Assertions),
PostgreSQL **1982 passed + 1 skipped** (12495; deckungsgleich, denn
`1983 = 1982 + 1`; der eine Unterschied ist der dokumentierte
`AllocationAtomicityTest`-Skip — der frühere Mailpit-Skip ist seit `7d2fe56`
weg).
Die frühere Fassung dieser Zeile nannte **1534 / 1533** — ein Snapshot vom
2026-09-27, der ~200 Tests hinter dem Stand lag. `AGENTS.md` §2 sagt dazu
ausdrücklich: die Zahlen sind ein Snapshot, kein Zustand, und wer sie zitiert,
prüft sie vorher gegen einen Lauf.

## Frontend-Key (SWR)

`useVenues(mandantId = null)` nutzt `venuesKey(mandantId)`: `null` →
`/api/admin/venues` (host-skaliert), eine Id →
`/api/admin/mandants/{id}/venues`. Die Combobox reicht `mandantId` an **Liste
und** Schreibaufrufe weiter — eine mandant-skalierte Liste allein genügt nicht,
der Inline-Create lief sonst weiter host-skaliert.

Die Invalidation nach Team-/Event-Mutationen (`teams_count` / `events_count`)
läuft über `refreshVenueLists(mandantId)`, das **alle** betroffenen Keys
invalidiert (beide Flächen). Sie hängt bewusst nicht mehr an einer
Key-Konstante: die Key-Ableitung steht neben `venuesKey()`, ein nicht
gemounteter Key ist ein No-op, ein fehlender ein stale Count.

