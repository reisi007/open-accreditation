# AGENTS.md — Backend (Laravel PHP)

Module-scoped operating guidelines for the Laravel backend in `backend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy,
agent roles, Postgres/SQLite portability) live in the repo root `AGENTS.md` and
apply here as well.

## Commands

Backend tests (PHP via Herd — PATH muss das PHP-Binary enthalten):

```bash
export PATH="/Users/florianreisinger/Library/Application Support/Herd/bin:$PATH"
cd backend && php artisan test
```

## Database Setup Policy (STRICT)

Dev/Prod nutzen **Postgres** (`bash scripts/e2e-up.sh` = `docker compose
--env-file deployment/dev.env -f deployment/docker-compose.yml --profile mail
up -d`; `--env-file` UND das `mail`-Profil sind Pflicht — siehe Kopf von
`deployment/docker-compose.yml`), Tests laufen auf **SQLite `:memory:`** (siehe
`phpunit.xml`).

Nach `php artisan migrate:fresh` MUSS `php artisan db:seed` (oder `--seed`
Flag) ausgeführt werden. Ohne Seed existiert kein Admin-User — Login und Auth
sind tot. Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit
`ADMIN_EMAIL`/`ADMIN_PASSWORD` an.

**Migration-Regel (STRICT):** Bei JEDER Migration gilt: **immer seeden**, nie
nur migrieren. `php artisan migrate` (bzw. `migrate:fresh`) allein reicht
nicht — anschließend IMMER `php artisan db:seed` (oder `--seed` Flag)
ausführen.

**Migration Policy (CRITICAL, etabliert 2026-08-13):** Migrationen werden mit
**Erstelldatum** nummeriert (Laravel-Standard `YYYY_MM_DD_HHMMSS_*`). Bis zum
**ersten Produktions-Deploy** gilt: Schema-Änderungen **erweitern** bestehende
Migrationen (Dateien dürfen frei angepasst werden — kein Versionsnummern-
Zeremoniell). **Nach dem nächsten Produktions-Deploy** erhält jede Schema-
Änderung eine **eigene, neue Migration** (Erstelldatum). **`down()`-Methoden
werden nie ausgeführt und können als Regel leer gelassen werden.**

### Prod-Schema via Compose — nicht manuell (STRICT)

Im **Prod** läuft das Schema **nicht** per `exec`, sondern automatisch bei
`docker compose … --profile prod up -d --build` (Ein-Befehl-Start, siehe
README „Deployment (Prod)"):

- Der One-Shot-Service **`migrate`** (Profil `prod`) führt über
  `deployment/entrypoint.sh` (Modus `migrate`) `php artisan migrate --force`
  → `storage:link` (nur falls der Link fehlt; das Image liefert ihn mit)
  → optional `db:seed` → `config:cache`/`config:clear` aus und beendet sich.
- **`backend`** hängt an `migrate: condition: service_completed_successfully`:
  schlägt der Deploy-Schritt fehl, startet der FPM-Service gar nicht. Es gibt
  also kein Fenster, in dem die App gegen ein veraltetes Schema ausliefert.
- **Seeding ist per Default AUS** (`RUN_SEEDER`, siehe unten) — die
  „immer seeden"-Regel oben gilt für **Dev/CI** (`scripts/e2e-up.sh`, `migrate
  --seed` lokal), nicht für einen Prod-Deploy ohne Zutun des Operators.
- Caching ist per Default AUS (`RUN_CACHE_WARMUP`). `config:cache` backt die
  Deploy-ENV als Schnappschuss ein; danach braucht jede ENV-Änderung ein
  Re-Deploy oder `php artisan config:clear`.

Manuelles `exec … php artisan migrate/db:seed` bleibt als **Escape-Hatch**
dokumentiert (Rollback, bestehender `migrate`-Container) — es ist aber nicht
mehr der Normalpfad.

## Parallel Testing (PHP) — SQLite `:memory:`

`paratest` ist installiert (`brianium/paratest`). Die Tests laufen via
`phpunit.xml` vollständig auf **SQLite `:memory:`** — kein DB-Container, keine
Worker-DBs:

- Canonical Test-DB: SQLite `:memory:` (aus `phpunit.xml`).
- `php artisan test --parallel` funktioniert out-of-the-box: jeder
  paratest-Worker-Prozess startet eine eigene, isolierte In-Memory-DB. Es
  existiert keine geteilte Instanz, auf der sich parallele Läufe gegenseitig
  zerstören können.

**KONKURRENZ-REGEL (STRICT, Subagenten):**

- `RefreshDatabase` migriert die In-Memory-DB bei jedem PHPUnit-Prozessstart
  frisch. SQLite `:memory:` macht parallele Läufe von Natur aus isoliert.
- Trotzdem: Die volle Suite läuft zur Reproduzierbarkeit IMMER NUR in EINEM
  Subagenten (einmal).
- Scoped-Runs (`--filter`) sind ohne Worker-DB-Setup direkt möglich:

```bash
php artisan test --filter <TestClass>
```

### Datei-Tests: `Storage::fake` und die zwei Race-Arten (STRICT, 2026-09-26)

Die DB ist per `:memory:` prozessisoliert, der **Dateisystem-Bereich nicht** —
dort gibt es zwei strikt zu trennende Fehlerbilder:

1. **Cross-Run-Rest (History-Abhängigkeit, WP-11 — GEBEUTEN).** Die Fake-Disk-
   Roots liegen unter `storage/framework/testing/disks/<disk>`: einem
   **geteilten, gitignorierten** Verzeichnis. Ohne Cleanup sammeln sich dort
   die echten Dateien aller Tests über die Läufe hinweg, bis reihenfolge-/
   historienabhängige Assertions brechen. Ursache war doppelt: `Storage::fake()`
   wurde nur von ~20 Testklassen aufgerufen — jeder Test **ohne** `fake()` schrieb
   auf die **produktiven** Roots `storage/app/private` (das teilen sich `local`
   und `private`) bzw. `storage/app/media`, also in das echte Dev-Media des
   Entwicklers; und nichts leerte `storage/framework/testing/disks/` nach einem
   Lauf. `Tests\TestCase` faked jetzt **alle** lokalen Disks (`local`,
   `private`, `media`, `public`) in jedem `setUp()` und leert deren Roots in
   jedem `tearDown()`. Folge: ein Test kann die Produktiv-Roots nicht mehr
   erreichen, und ein Lauf hinterlässt keine Reste. **Ein wiederholter
   Volllauf im selben Checkout ist damit reproduzierbar** — wer Residue findet,
   hat einen echten Bug (siehe `tests/Feature/TestDiskIsolationTest.php`, der
   genau das als Regressions-Guard festnagelt). `storage/app/**` wird
   **niemals** gelöscht: im Dev-Checkout steckt dort echtes Media.
2. **Cross-Prozess-Race (weiterhin real).** Zwei gleichzeitig laufende
   Vollausführungen im selben Checkout teilen dieselben Disk-Roots und
   löschen sich gegenseitig die Dateien des jeweils anderen Tests. Das ist der
   klassische 3-Flake-Fall. **Gegenmittel bleibt: die volle Suite läuft
   gleichzeitig immer nur in EINEM Subagenten** — geteilte Disk-Roots, nicht
   die DB sind der limitierende Faktor. Scoped-Runs (`--filter`) zweier
   Subagenten mit disjunkten Klassen sind dagegen unkritisch (siehe oben).

## Portabilitätsregel (CRITICAL)

Schema/Queries müssen zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel bleiben:

- **Kein PG-spezifisches SQL** in Migrationen/Queries.
- JSON-Spalten via Laravel `json`-Type (kein rohes `jsonb`).
- Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen.
- Wo Postgres-Features nötig sind → Service-Abstraktion + separater
  Integrationstest (in `features/` dokumentieren).
