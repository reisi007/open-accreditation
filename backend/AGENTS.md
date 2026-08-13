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

Dev/Prod nutzen **Postgres** (`docker compose -f deployment/docker-compose.yml
up -d`), Tests laufen auf **SQLite `:memory:`** (siehe `phpunit.xml`).

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

## Portabilitätsregel (CRITICAL)

Schema/Queries müssen zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel bleiben:

- **Kein PG-spezifisches SQL** in Migrationen/Queries.
- JSON-Spalten via Laravel `json`-Type (kein rohes `jsonb`).
- Datumsarithmetik über Query-Builder/Eloquent statt roher PG-Funktionen.
- Wo Postgres-Features nötig sind → Service-Abstraktion + separater
  Integrationstest (in `features/` dokumentieren).
