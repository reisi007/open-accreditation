# 02 — Domain-Model

## SOLL — Entity-Übersicht

Hierarchie: **Mandant (Verband) → Team (Verein, optional) → Kategorie →
Akkreditierung (Quota + Frist) → Application → Sub-Akkreditierung.**

## P1 — Im Ist umgesetzt

| Entity | Spalten (Auszug) | Anmerkung |
|---|---|---|
| `mandants` | `slug` (unique), `name`, `logo_path`, `header_path`, `impressum_text`, `privacy_text`, `smtp_config` (**`text`, verschlüsselt**), `teams_enabled` (bool, default false), `is_primary`, `is_active`, timestamps | Verband, eigene Domain, kein Theme. `smtp_config` ist **kein** JSON-Feld mehr, sondern Laravel-Chiffretext (Cast `encrypted:json`) — siehe „WP-6-d". |
| `mandant_domains` | `id`, `mandant_id` (FK, cascade, indexed), `hostname` (unique), timestamps | Hostname dient als Lookup-Index für `MandantContext::resolve()`; unique constraint = Suchindex. |
| `users` | `name`, `email`, `password` (hashed), `email_verified_at`, **Profil-Felder** `title/gender/birth_date/street/zip/city/country/company/phone/fax/branch/position/vest_available/vest_number`, **Aktivierung** `activation_token` (64, unique, nullable), `activation_token_expires_at`, `rememberToken`, timestamps; `unique(['mandant_id','email'])` + **`index('email')`** | `branch` als Enum-Werte in der API (`print/tv/online/radio/photo/other`). Konto gehört genau einem Mandanten (über `role_user.mandant_id`), kein eigenes `mandant_id`-Feld am User. `email` ist **per Mandant** unique (BE-R1) — der zusätzliche Einzel-Index `users_email_index` ist **nicht** unique und bedient nur den Login-Lookup ohne `MandantContext` (siehe „WP-6-c"). |
| `roles` | `id`, `name`, `slug` (unique), `description`, timestamps | Fünf Rollen, Slug ist Source of Truth (`app/Enums/UserRole.php`). |
| `role_user` | `id`, `user_id` (FK, cascade), `role_id` (FK, cascade), `mandant_id` (FK nullable, cascade), `team_id` (unsignedBigInteger nullable, FK cascade seit P2), **`unique (user_id, role_id, COALESCE(mandant_id,0), COALESCE(team_id,0))`** (`role_user_scope_unique_coalesced`), timestamps | Pivot mit Scope. `mandant_id = NULL` = globaler `super_admin`. Die Unique-Constraint ist ein **Ausdruck-Index** über normalisierte NULL-Scopes — siehe „WP-6-b"; der frühere Plain-Index `role_user_scope_unique` war wirkungslos und ist entfernt. |
| `user_media` | `id`, `user_id` (FK, cascade), `type`, `path`, `mime`, `size`, `original_name`, timestamps; Index `[user_id, type]` | Private (auth-gated) Fotos/Anhänge auf Disk `private` (`storage/app/private`). `type` ∈ `portrait/press_id/attachment`; `portrait`/`press_id` singular (ersetzen), `attachment` mehrwertig. `path` wird nie als Public-URL exponiert. |

## API-Vertrag (Ist P1, Auszug)

- `GET /api/user/media/{media}` — auth-gated Delivery, **Owner-only** (403
  für Fremde), Disk `private`, `Content-Type` aus `user_media.mime`.
- `PUT /api/user/profile` — nur eigene Profil-Felder (keine User-ID im
  Request → kein Cross-User-Write).
- Details in `features/auth/01-auth-and-roles.md`.

## P2/P3 — Ausblick (noch nicht umgesetzt)

| Entity | Beschreibung |
|---|---|
| `teams` | Vereine (optional je Mandant, `teams_enabled`), Heimstätte (Default-Ort), Kategorie-Overrides; `role_user.team_id` bekommt dann den FK |
| `categories` | z. B. Presse, Fotograf, Delegation; erbt vom Mandant, Team überschreibt |
| `events` | Titel, Datum, Ort (Default = Heimstätte, überschreibbar), Wettbewerb, Frist (Default/Override); Ebene Mandant oder Team |
| `accreditations` | Kategorie + Event/Scope, Quota, Frist, VIP/Blacklist-Konfiguration |
| `applications` | Antrag: Kategorie/Scope, Status `requested/approved/denied/blacklisted`, Foto/Anhänge. Status-Set dauerhaft: die Engine setzt **nie** `blacklisted` (nutzt `denied` + `reason`; der `blacklisted`-Status ist für die Blacklist-Verwaltung in P3e reserviert) — Details `accreditation/01-allocation-engine.md` |
| `sub_accreditations` | Park-/Sitzkarte, nur bei Haupt-Akkreditierung, eigenes Kontingent, auto/manuell |
| `badge_templates` | Ausweis-Layout (Feld-Set, Positionen, Logo/Header/Farben) |
| `blacklists` | Gesperrte Personen + Domänen (Block auf Mandant-Ebene); Enforcement in `accreditation/01-allocation-engine.md` |
| `wallet_passes` | Apple/Google Wallet (PKPASS) je Akkreditierung |

## Anmelde-Scopes

Event/Spiel · Liga-weit · Saison (Verein) · Pro-Spiel.

## Portabilitätsregel

Schema/Queries bleiben zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel: kein PG-spezifisches SQL in Migrationen/Queries,
JSON-Spalten via Laravel `json`-Type, Datumsarithmetik über Query-Builder/
Eloquent. Wo Postgres-Features nötig sind → Service-Abstraktion + separater
Integrationstest.

**Ausdrücklich portable ANSI-SQL-Konstrukte, die hier erlaubt UND gewollt
sind** (beide Engines, live verifiziert auf Postgres 17.10 / SQLite 3.45.2 —
kein PG-only, also keine Service-Abstraktion nötig):

- `ORDER BY <col> ASC NULLS LAST` — Postgres seit 9.x, SQLite seit 3.30.
- `CREATE UNIQUE INDEX … (col, …, (COALESCE(col, 0)), …)` — Ausdruck-Index;
  Postgres verlangt je Ausdruck eine eigene Klammer, SQLite akzeptiert sie.
  Nur so ist eine Unique-Constraint über nullable Scope-Spalten auf BEIDEN
  Engines überhaupt scharf (siehe „WP-6-b").

**Nicht-portabel und daher verboten bleibt** insbesondere alles, was die
SQLite-Testsuite nicht gegen dieselbe Fehlerklasse absichert — das prominenteste
Beispiel ist der Laravel-`json`-Typ: SQLite bildet ihn auf `text` **ohne**
`json_valid()`-Check ab, Postgres lehnt Nicht-JSON mit
`invalid input syntax for type json` ab. Ein Cast, der auf SQLite einen
Klartextwert schreibt, kann auf Postgres ein Laufzeit-Fehler werden (siehe
„WP-6-d").

## WP-6 — Schema-Härtung (2026-09-26)

Work-Paket „Schema + Portabilität" des Whole-Project-Code-Reviews. Alle
Änderungen liegen in **drei neuen Migrationen** (siehe „Extend vs. new" unten),
keine bestehende Migration wurde nachträglich editiert.

### WP-6-a (R-D6) — NULL-Ordering explizit: `ASC NULLS LAST`

`events.date` und `event_participants.sort_order` sind nullable. Ein
`ORDER BY <col> ASC` sortiert die beiden Engines **unterschiedlich**:

| Engine | `ASC` mit NULL | Reihenfolge |
|---|---|---|
| Postgres | NULLS **LAST** | 01.01., 01.05., NULL |
| SQLite | NULLS **FIRST** | NULL, 01.01., 01.05. |

Damit rendern Prod und Testsuite dieselbe Query unterschiedlich — genau die
Divergenz, die die Portabilitätsregel oben verbietet. Betroffen waren zwei
Live-Listen: der öffentliche Portal-Kalender (`GET /api/portal/events`) und
die Teilnehmerliste eines Events (`Event::participants()`).

**SOLL:** Sortierung auf ein **eindeutiges, ausdrückliches** `ASC NULLS LAST`
festgeschrieben. Undatierte Events und Slots ohne Nummer stehen damit
**hinter** den datierten/numerierten — was Postgres seit jeher tat.

> **Bewusste Verhaltensänderung:** Auf SQLite hat sich die *effektive*
> Anzeigereihenfolge dadurch geändert (undatierte Einträge standen vorher
> vorne). Die Suite hat damit vorher den Bug festgeschrieben. Die
> Referenz ist das Produktionsverhalten, nicht die alte Test-Erwartung.

### WP-6-b — `role_user`: Unique-Constraint, die NULL-Scopes wirklich greift

`unique(['user_id','role_id','mandant_id','team_id'])` war **wirkungslos**:
NULLs sind in einem zusammengesetzten Unique-Index auf Postgres **und**
SQLite distinct, und jede existierende Rolle hat mindestens eine NULL-Spalte
im Tupel.

| Rolle | `mandant_id` | `team_id` | vom alten Index geschützt |
|---|---|---|---|
| `super_admin` | NULL | NULL | nein |
| `mandant_admin` | gesetzt | NULL | nein |
| `verifier` | gesetzt | NULL | nein |
| `user` | gesetzt | NULL | nein |
| `team_admin` | gesetzt | gesetzt | ja |

Praktische Folge: doppelte globale `super_admin`-Zeilen waren möglich (und
wurden von `AdminUserResource::rolesPayload` doppelt gerendert); auf dem
Selbstregistrierungs-Pfad (`AuthController::register`, `team_id => null`) griff
gar nichts.

**SOLL:** Ausdruck-Index über normalisierte Scopes —
`unique (user_id, role_id, COALESCE(mandant_id, 0), COALESCE(team_id, 0))`,
Indexname `role_user_scope_unique_coalesced`. Die Sentinelle `0` ist sicher,
weil `id` bei 1 beginnt. Der alte Index ist **entfernt**: er war eine echte
Teilmenge des neuen und hätte nur Write-Amplifikation erzeugt.

> **Präzedenz, bewusst NICHT kopiert:** `0001_01_01_000000_create_users_table.php:27-30`
> dokumentiert dieselbe NULL-distinct-Situation für `users` — und dort ist sie
> **gewollt**, weil mehrere globale Accounts mit derselben E-Mail legal sein
> müssen (BE-R1). Beide Fälle sehen gleich aus und müssen getrennt
> entschieden bleiben; `RoleUserScopeUniqueTest` nagelt beide Richtungen fest.

### WP-6-c — Einzelindex auf `users.email` (Login-Hot-Path)

`unique(['mandant_id','email'])` kann ein Prädikat auf `email` allein nicht
bedienen (B-Tree, führende Spalte zuerst). `AuthController::findLoginUser()`
nimmt genau diesen Zweig, sobald `MandantContext::currentId() === null` ist
(unbekannter Host, CLI, Tests) — ein **sequenzieller Vollscan von `users`**
auf der Login-Strecke, gedrosselt auf 15 Requests/Min/& IP. Neu:
`users_email_index` (**nicht** unique — die Per-Mandant-Uniqueness von BE-R1
bleibt im zusammengesetzten Unique-Index die Durchsetzungsschicht).

### WP-6-d (R-D9) — `smtp_config` verschlüsselt (und warum die Spalte `text` sein muss)

`Mandant::$casts['smtp_config']` ist `encrypted:json`. `MandantResource`
maskierte das Passwort bisher nur am API-Rand; jeder DB-Lese-Pfad (Backup,
Dump, Replica) lieferte benutzbare Fremd-Mail-Credentials — anders als die
bcrypt-Hashes daneben.

**Die Spalte musste dabei von `json` auf `text` wechseln.** Laravels
`Encrypter::encrypt()` liefert `base64_encode(json_encode([…]))`, also einen
**nackten Base64-String**. Ein `json`-Typ lehnt das auf Postgres ab:

```
ERROR:  invalid input syntax for type json
DETAIL:  Token "eyJpdiI6IjEyIiwidiI6IjEiLCJtYWMiOiIiLCJ0YWciOiIifQ" is invalid.
```

SQLite hätte das **nicht** bemerkt, weil seine Grammatik `json` auf `text`
abbildet (kein `json_valid()`-Check) — dieselbe Klasse Fehlblindheit wie
oben. `text` ist außerdem der Typ, für den der `encrypted`-Cast entworfen ist,
und macht beide Engines gleich.

**Operator-Aktion (Bestandsdaten):** Zeilen, die vor dem Cast geschrieben
wurden, enthalten Klartext-JSON und sind danach **nicht mehr lesbar** (sie
werfen `DecryptException` — bewusst laut, nicht still). Es gibt noch keine
Produktionsdaten (Go-Live geparkt), die Behebung ist deshalb je Mandant ein
**Re-Save**: `PUT /api/admin/mandants/{id}` mit demselben `smtp_config`, oder
`smtp_config: null`, um die Zugangsdaten zu verwerfen. Ein Code-Pfad, der
alle Mandanten blind neu speichert, existiert bewusst nicht — der Operator
entscheidet, ob die Credentials überhaupt noch gültig sind. Eine
`APP_KEY`-Rotation ohne den alten Schlüssel in `APP_PREVIOUS_KEYS` invalidiert
die Config erneut.

### WP-6-e — `APP_MAINTENANCE_DRIVER` defaultet auf `database`

Laravels Default `file` schreibt `storage/framework/down` und wirkt nur, wenn
**jede** Instanz dieselbe Datei teilt: auf einem Replica-Set bleiben die
anderen Instanzen live im Betrieb, während `php artisan down` „erfolgreich"
war. `.env.example` liefert jetzt `database`; `file` bleibt als bewusste
Single-Instance-Entwickler-Entscheidung per Env überschreibbar.
`phpunit.xml` pinnt weiterhin auf `file`, damit ein Maintenance-Test niemals
die Test-DB anfasst.

### Extend vs. new — warum drei neue Migrationen (trotz „erweitern bis Go-Live")

`backend/AGENTS.md` erlaubt vor dem ersten Produktions-Deploy das Erweitern
bestehender Migrationen. Für dieses Paket gilt trotzdem „new", und zwar aus
drei Gründen:

1. **Umgebungen, die die alten Dateien schon angewandt haben.** Der
   Prod-Deploy-Pfad (`deployment/entrypoint.sh`, Modus `migrate`) führt
   `migrate --force` aus, **kein** `migrate:fresh`; der CI-E2E-Job und der
   Dev-Postgres-Container machen es ebenso. Eine editierte Alt-Migration wird
   dort nie erneut ausgeführt — der Fix käme dort nie an. Das ist auch
   unabhängig vom Go-Live-Status bereits real.
2. **Repo-Präzedenz.** `2026_08_14_000007`, `…_000008`, `…_000010` und
   `2026_08_26_000001` sind sämtlich „add X to bestehender Tabelle"-Migrationen
   — alle vor dem Go-Live entstanden. Neue Migrationen sind hier also die
   etablierte Praxis, nicht die Ausnahme.
3. **Index/Constraint-Wechsel sind keine Tabellenerweiterung.** WP-6-b ersetzt
   einen Index (inkl. `DROP`), WP-6-d verändert einen Spaltentyp. Beides ist
   als abgeschlossene, benannte Operation lesbar — eine Diff-Zeile im
   `create`-Statement eines drei Monate alten Files wäre das nicht.

### Extend vs. new — Grenze der Regel

Die „erweitern"-Option bleibt für **Tabellenerweiterungen innerhalb einer noch
laufenden Phase** die richtige Wahl. Eine Faustregel: sobald eine Migration
einmal auf einer Umgebung gelaufen sein *kann*, die nicht per
`migrate:fresh` neu aufgebaut wird, bekommt jede weitere Änderung eine eigene
Migration. `down()` bleibt per Repo-Regel leer; die exakten Statements, die ein
`down()` bräuchte, stehen als Kommentar in den Dateien.

### Engine-Verifikationsstand (2026-09-26)

**Doppelt engine-verifiziert.** Alle fünf neuen Testdateien wurden nicht nur
gegen SQLite 3.45.2 (Test-Suite), sondern auch **live gegen Postgres 17.10**
gefahren (Docker-Container `accriditation_db`, eigene Wegwerf-Datenbank, per
`DB_*`-Prozessenv — `phpunit.xml` `<env>` überschreibt ohne `force` den
Prozessenv nicht, der Lauf ist also wirklich auf Postgres gelaufen): **38/38
grün**. Ebenso die elf Bestandsdateien, die WP-6 am stärksten exponiert
(`PortalTest`, `AdminTeamParticipationTest`, `AdminMandantTest`,
`MandantMailerTest`, `MailTest`, `DatabaseSeederTest`, `RoleAssignmentTest`,
`AuthRegisterTest`, `AuthLoginTest`, `AdminEventTypeTest`,
`SubAccreditationTest`) mit **313/313 grün**. Und die **komplette
Migrationskette** lief per `migrate --force` auf einem frischen Postgres
durch, inklusive aller drei neuen Migrationen — `pg_indexes` und
`information_schema` bestätigen Indexnamen, Indexdef und den Spaltentyp
`text`.

Damit ist engine-verifiziert:

- `NULLS LAST` auf beiden Engines, inklusive der beobachteten
  Default-Divergenz (`NullOrderingTest`).
- Der Ausdruck-Index inklusive `UniqueConstraintViolationException` für
  globale `super_admin`- und Registrierungs-Duplikate, und dass
  `team_admin` mit Team weiter geschützt bleibt.
- `ALTER TABLE … ALTER COLUMN … TYPE text` auf `json` **mit** vorhandenen
  Zeilen.
- Dass ein Base64-Chiffretext in einer `json`-Spalte auf Postgres scheitert
  (der Grund für WP-6-ds Spaltenwechsel) — und dass er in `text` passt.

**Weiterhin nur behauptet, nicht engine-verifiziert:**

- Ein **kompletter** Suite-Lauf (alle 1280 Tests) gegen Postgres. Getestet
  wurden die WP-6-nahen Dateien, nicht der Rest der Suite.
- Die **Performance-Wirkung** der Indizes (der Login-Scan, siehe „WP-6-c").
  Existenz und Form sind geprüft, ein `EXPLAIN ANALYZE` oder ein Messwert
  unter Last steht aus.
- `SchemaHardeningTest::test_the_smtp_config_column_is_text_so_postgres_accepts_the_ciphertext()`
  ist auf SQLite grün, ob der Fehler behoben ist oder nicht — der Test ist
  erst im Postgres-Lauf aussagekräftig. Die engine-agnistische Entsprechung
  ist `test_the_stored_smtp_config_is_no_longer_a_json_document()`.

Das verbleibende Gate ist das **„Postgres-Portabilitäts-Gate"** des
Go-Live-Plans (`AGENTS.todo.md`, Phase A) — es ist weiterhin **offen** und
soll die Suite vollständig (inkl. der nicht WP-6-nahen Dateien) gegen echtes
Postgres fahren.

## P2-F2 — User-Suche: akzeptiertes Risiko (kein Index bei führendem Wildcard)

Die Admin-User-Suche filtert mit `LOWER(users.name) LIKE '%term%'` (führendes
Wildcard). Ein B-Tree-Index — auch funktional auf `LOWER(name)` — kann eine
`LIKE`-Prädikat mit führendem `%` weder in SQLite noch in Postgres bedienen:
beide Engines sequenz-scannen die Tabelle. Ein Trigram-/GIN-Index wäre
rein PG-only und verletzt die Portabilitätsregel (§2).

**Akzeptiertes Risiko:** Der Index wurde bewusst aus der `users`-Migration
entfernt — er würde nur Write-Amplifikation erzeugen, ohne die Ziel-Query
zu beschleunigen. Bei wachsendem Bestand (>10⁵ User/Mandant) ist ein
dedizierter Suchservice (z. B. Meilisearch/Elasticsearch) oder ein
PG-only Trigram-Index (mit Service-Abstraktion + separatem
Integrationstest) vorzusehen. Bis dahin ist der Seq-Scan tragbar
(Users/Mandant ist klein, Suche ist Admin-only).

## P2-F1 — User-Suche: Non-ASCII-Divergenz (akzeptiertes Risiko)

`LOWER()` in SQLite faltet **nur ASCII** (`A-Z` → `a-z`); nicht-lateinische
Zeichen wie `Ü`, `Ö`, `Ä`, `ß` bleiben unverändert. Postgres `LOWER()` ist
Unicode-aware und faltet auch Non-ASCII. Die portable `LOWER(col) LIKE
LOWER(?)`-Kontrakt (CC-R1) ist daher für ASCII-Terme identisch, divergiert
aber bei Non-ASCII:

- SQLite: Suche nach `müller` matcht **nicht** `MÜLLER` (weil `LOWER('Ü') = 'Ü'`).
- Postgres: Suche nach `müller` matcht `MÜLLER` (weil `LOWER('Ü') = 'ü'`).

**Akzeptiertes Risiko:** Die SQLite-Testsuite (PHPUnit, `SQLite :memory:`)
validiert daher den ASCII-Pfad; der Non-ASCII-Pfad ist gegen Postgres
manuell/integrationstest zu prüfen. Für die anfängliche Admin-Suche (kleine
User-Zahlen/Mandant, ASCII-Domänen) tragbar. Sauberer Fix: `mb_strtolower()`
im Service-Layer (PHP-seitig, engine-unabhängig) — folgt später, wird hier
als Portabilitäts-Trade-off dokumentiert.

## Applications: `created_at` ist der Antragszeitpunkt (kein `applied_at`, P3b-F2)

Die Tabelle `applications` besitzt **kein** eigenes `applied_at`-Feld — der
Antragszeitpunkt **ist** `created_at` (Laravel-Timestamps): beim Anlegen des
Antrags (`POST …/apply`) gesetzt und danach unveränderlich; Statuswechsel der
Engine/Admin-Aktionen schreiben nur `status`/`reason` (und `updated_at`
als Nebenprodukt), nie `created_at`.

- **API-Vertrag:** Exponiert wird ausschließlich **`created_at`**
  (ISO-8601, `ApplicationResource`). Ein Feld `applied_at` existiert weder im
  Schema noch in der API — Client-Seite darf sich darauf nicht stützen.
- **FCFS-Ordering:** Die Allocation-Engine nutzt `created_at ASC` als
  Eingangsreihenfolge (= Antragseingang), siehe
  `accreditation/01-allocation-engine.md`.
- **Kein Decision-Zeitstempel:** Der Freigabe-/Ablehnungszeitpunkt wird
  aktuell **nicht** persistiert oder exponiert (`updated_at` ist kein
  verlässlicher Ersatz — z. B. ändert eine spätere VIP-Setzung via
  `setPriority` den Wert ohne Statuswechsel). Wird ein Audit-Zeitstempel für
  die Freigabe-Entscheidung benötigt → eigene neue Spalte (SOLL, P3e-Cleanup),
  kein Überladen von `created_at`.

## BE-R8 — Bulk-Reanimations-Limitation (bewusste Design-Entscheidung)

Alle Bulk-Pfade der Allocation-Engine (`approveSelection`, `approveAllEligible`
— manuell wie automatisch) kandidieren ausschließlich über `eligibleRequested()`,
d. h. nur Zeilen im Status **`requested`**. `denied`-Anträge (inkl. VIP-Priorität)
bleiben durch jeden weiteren Bulk-Run dauerhaft `denied` — auch nach Quota-Erhöhung
oder Blacklist-Löschung. `approved`-/`blacklisted`-Zeilen sind ebenfalls nie
Bulk-Kandidaten (Idempotenz).

**Warum:** Bulk-Läufe bleiben deterministisch und idempotent; die Reaktivierung
abgelehnter Anträge ist eine bewusste Admin-Entscheidung im Einzelfall
(Sicherheit/Datenintegrität — keine versehentliche Massen-Reaktivierung).

**Workaround:** Einzel-Reanimation via `AllocationService::approveApplication()`
(erlässt als Ausgangsstatus `requested | denied`, Blacklist- und Quota-Check
laufen erneut) oder manueller Status-Reset.

**Referenz:** Detail-Dokumentation in
`features/accreditation/01-allocation-engine.md` (Abschnitt
"Bulk-Reanimations-Limitation").

## `is_team_override` Semantik (P2b-F5)

`CategoryResource` exponiert einen abgeleiteten Boolean **`is_team_override`** —
kein DB-Feld, definiert als `team_id !== null`
(`backend/app/Http/Resources/CategoryResource.php`). Er markiert jede
Kategorie-Zeile auf **Team-Ebene** (Verein), unabhängig davon, ob fachlich
ein Verbands-Datensatz mit gleichem Slug tatsächlich „überschrieben" wird.

- **Semantik-Kosmetik (bewusst akzeptiert):** Das „Team-Override"-Badge in der
  Admin-UI (`frontend/src/pages/admin/CategoriesPage.tsx`) erscheint auf
  **jeder** Team-Kategorie — auch wenn es streng genommen nur ein
  „Team-Level"-Flag ist. Der Flag darf nicht als Nachweis eines echten
  Slug-Overrides missdeutet werden.
- **UI-Konsequenz:** Team-Kategorien werden visuell als Override gekennzeichnet,
  obwohl sie ggf. nur eine erste Team-Level-Instanz sind. Kein Schema-/API-
  Change nötig; Details in `features/01-multi-tenancy.md`.
