# open-accreditation

Moderne, mandantenfähige **Akkreditierungs-Plattform (Multi-Tenant)** für
Sportverbände und Vereine — Nachbau des Feature-Umfangs von Sportdata
„Accreditation Services" (set.sportdata.org) mit eigenem Stack.

Super Admin verwaltet Mandanten (Verbände), optional Teams (Vereine),
Kategorien (z. B. Presse), Events (Spiele), Selbstanmeldung, Freigabe-Workflow,
Ausweis-Druck (PDF), QR-Verifikation, PKPASS-Wallets sowie Park-/Sitzkarten als
Sub-Akkreditierungen.

## Architektur

```
backend/     Laravel 13 API (JWT httpOnly-Cookie, Postgres, SQLite :memory: Tests)
frontend/    React 19 + Vite + TypeScript + Tailwind v4 + daisyUI v5 (Lingui DE/EN)
deployment/  Dockerfile (PHP-FPM, pgsql) + entrypoint.sh + docker-compose
             (Postgres + Mailpit + einmaliger `migrate`-Deploy-Schritt)
features/    Dauerhafter SOLL-Zustand (Multi-Tenancy, Domain-Model)
```

## Lokales Setup

Voraussetzungen: PHP 8.5 (z. B. via Homebrew: `brew install php`),
Composer, Docker, Node.js + pnpm (Major-Linie 11; kein Pin).

**Nach einem Neustart startet Docker nicht von allein.** Bei **Rancher Desktop** liegt der Socket
unter `~/.rd/docker.sock`, **nicht** unter `/var/run/docker.sock` — `docker info` schlägt dann mit
`connect: no such file or directory` fehl und der Fehler sieht nach einem Skript-Problem aus, nicht
nach einem Docker-Problem. `open -a "Rancher Desktop"`, dann warten, bis `docker info` antwortet
(hat gedauert: ~50 s), **erst dann** `scripts/e2e-up.sh`.

**Und `scripts/e2e-up.sh` startet die Server nicht** — es legt Postgres, Mailpit, Migrationen und
Seeds an und **druckt** die URLs (Schritt 3/4 unten). `php artisan serve` und `pnpm dev` sind **zwei
zusätzliche, manuelle Schritte**. Die Screenshot-Config hat **kein** `webServer` und liest
`E2E_BASE_URL` (Default `5173`), also muss Vite laufen.

Das Backend ist lokal über **`php artisan serve` unter `http://localhost:8000`**
erreichbar (Vite-Proxy in Schritt 3 bleibt gültig). Alternativ kann es über
einen Webserver (z. B. Caddy oder Apache) als Site auf das `backend/`-Verzeichnis
gesetzt werden; `APP_URL` ist entsprechend anzupassen.

**`memory_limit`.** Homebrews `php.ini` steht bei **128M**; lokal wurde er am 2026-09-28 auf **1G**
gehoben (Backup: `php.ini.bak.<Zeitstempel>` daneben). **Der Grund ist Reserve, nicht ein
bekannter Fehler:** `BadgeRenderServiceTest` — der speicherhungrigste Pfad (dompdf + QR) — meldet
einen Peak von **97 MB** und läuft **sogar mit harter 96M-Grenze** durch. 128M hat den Ausweis-Export
also **nicht** umgeworfen.

**Diese Zahl nicht mit dem RSS verwechseln.** Derselbe Lauf belegt ~**134 MB** *resident set size*.
Die Differenz sind Binär, Extensions und opcache — und `memory_limit` zählt **PHPs eigene
Allokationen**, nicht den RSS. Wer beide gleichsetzt, schliesst aus einer Zahl die falsche
Grenze. (Genau das stand hier vorher als Warnung, auf Basis genau dieser Verwechslung — die
Messung hat sie widerlegt.)

1G deckt ab, was 128M **knapp** hielt: die volle Suite mit paratest-Workern und echte Serien-Exporte.

Mandanten-Domains (z. B. `bundesliga.test`) werden über den Host aufgelöst —
die entsprechenden Einträge müssen in `/etc/hosts` hinterlegt
werden, sonst 404t die `MandantContext`-Middleware. Der Primary-Mandant
`main` ist auf `accreditation.test` (+ `www`) und `localhost` gemappt.

```bash
# 1. Infra starten (Postgres 17 + Mailpit)
#    `deployment/dev.env` (versioniert, DEV-ONLY, nur öffentliche Platzhalter)
#    liefert die Pflicht-Variablen der Compose-Datei; Mailpit liegt im
#    `mail`-Profil. Prod nutzt NIE diese Datei (siehe Kopf von
#    deployment/docker-compose.yml).
docker compose --env-file deployment/dev.env \
               -f deployment/docker-compose.yml --profile mail up -d

# 2. Backend-Setup (idempotent; anpassbar via ADMIN_EMAIL/ADMIN_PASSWORD)
bash scripts/e2e-up.sh
#    = composer install (falls fehlt) nicht enthalten — einmalig manuell:
#      cd backend && composer install
#    Der Script-Teil macht: .env aus .env.example, key:generate (falls leer),
#    jwt:secret (falls leer), migrate --force, db:seed --force.
#    (Schritt 1 ist in dem Skript bereits enthalten.)

# 3. Frontend
cd frontend && pnpm install && pnpm dev   # http://localhost:5173
```

**Worker & Scheduler in Dev.** `scripts/e2e-up.sh` startet nur die Infra
(Postgres + Mailpit); das Dev-Backend läuft host-native über
`php artisan serve`, es gibt also keinen Container, der den Prod-Supervisor
aufnehmen könnte. Damit `allocation:run`/`reminders:send` **auch in dev**
laufen (`features/accreditation/01-allocation-engine.md:408`), startet

```bash
bash scripts/dev-worker.sh
```

denselben Takt host-native: `queue:work --tries=5 --timeout=60` in einer
Restart-Schleife und `schedule:run` alle 60 s, konfiguriert aus `backend/.env`.
Im Vordergrund; Ctrl-C beendet beide Schleifen.

Die Einzelschritte aus `scripts/e2e-up.sh` manuell:

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret
php artisan migrate --seed   # immer seeden — ohne Seed kein Admin-User
php artisan serve            # Backend: http://localhost:8000 (Mail-UI: http://localhost:8025)
```

### Login-Daten (Dev)

Der `DatabaseSeeder` legt den Admin via `firstOrCreate` mit
`ADMIN_EMAIL`/`ADMIN_PASSWORD` aus der `.env` an. **Dev-Default** (nur lokal,
kein echter Wert): `admin@example.com` / `admin`.

### Mandanten-/Domain-Konzept

Jeder Mandant (Verband) besitzt eine **eigene Domain** (Super Admin → Mandant →
Team → Kategorie → Akkreditierung → Application). Mandanten-Isolation über
`MandantContext`-Middleware + `forCurrentMandant()`-Scopes — Details/SOLL in
`features/`.

## Deployment (Prod)

Voraussetzung neben Docker: der MEDIA_ROOT auf dem Host, UID/GID 33
(`www-data` — der Container läuft non-root und kann Rechte nicht reparieren):

```bash
sudo mkdir -p /srv/media/accreditation
sudo chown -R 33:33 /srv/media/accreditation
```

Der komplette Prod-Start ist **ein** Befehl:

```bash
export APP_KEY="$(openssl rand -base64 32)"   # NIE im Repo
export DB_USERNAME=…                          # identisch für `db` und `backend`
export DB_PASSWORD=…
docker compose -f deployment/docker-compose.yml --profile prod up -d --build
```

`APP_KEY`, `DB_USERNAME` und `DB_PASSWORD` sind Pflicht — Compose bricht mit
einer `:?`-Meldung ab, statt mit einem Default zu starten (ein *funktionierender*
Default-`APP_KEY` im Repo hieße: alle `Crypt`-Payloads und QR-Tokens forgerbar).
`--env-file deployment/dev.env` wird im Prod-Pfad **nie** benutzt; die Datei ist
DEV-ONLY.

**Build-Kontext:** der `backend`-Service baut mit Kontext = **Repo-Root** (damit
`backend/` ins Image kommt), gefiltert über die `.dockerignore` im Repo-Root. Die
zieht `frontend/node_modules`, `backend/vendor`, `.git`, `backend/storage/`,
Test-Artefakte und `.env*` heraus — von 359,7 MB auf 4,1 MB pro Build (gemessen,
WP-5-D1). Sie fasst `deployment/` nicht an, weil die CI-Basis-Image-Rolle mit
`context: deployment` baut und dort `deployment/entrypoint.sh` braucht; eine
`deployment/Dockerfile.dockerignore` würde die Root-Regeln still ersetzen und
darum nicht existieren. Details in `.dockerignore` und im Kopf von
`deployment/Dockerfile`.

Was der Start macht — Compose zieht die Abhängigkeiten von `backend` hoch:

| Reihenfolge | Service | Rolle |
|---|---|---|
| 1 | `db` | Postgres, Start erst nach `service_healthy` |
| 2 | `migrate` | **einmaliger** Deploy-Schritt (siehe unten), läuft genau einmal pro Deploy |
| 3 | `backend` | PHP-FPM auf `127.0.0.1:9000` (Caddy-Upstream `fastcgi 127.0.0.1:9000`) **plus Queue-Worker + Scheduler** (Supervisor, siehe unten), startet erst nach `service_completed_successfully` von `migrate` |

Der `migrate`-Service ist ein **One-Shot** ohne `restart`. Er läuft über
`deployment/entrypoint.sh` im Modus `migrate` durch `php artisan migrate
--force` → `storage:link` (nur wenn der Link fehlt; das Image liefert ihn mit)
→ optional `db:seed` → `config:cache`/`config:clear` und beendet sich. Der
MEDIA_ROOT-Guard (Exit 78) läuft in **beiden** Modi. Schlägt der Schritt fehl,
startet `backend` **gar nicht** — die App liefert nie Traffic gegen ein
veraltetes Schema. Ein `docker compose restart backend` führt die Migrationen
nicht erneut aus.

### Worker & Scheduler (Position 45/46)

`backend` startet nicht mehr direkt `php-fpm`, sondern
`deployment/backend-supervisor.sh` (im Image als
`/usr/local/bin/accriditation-backend-supervisor`). Der Supervisor:

1. validiert die Queue-Konfiguration **fail-closed** — `QUEUE_CONNECTION=database`,
   `DB_QUEUE_CONNECTION == DB_CONNECTION` (damit `after_commit` Queue und DB in
   derselben Transaktion hält) und `QUEUE_WORKER_TIMEOUT < DB_QUEUE_RETRY_AFTER`
   (Default 60 < 90, verhindert die doppelte Reservierung eines hängenden Jobs);
2. löscht stale PID-Marker, startet `queue:work --tries=5 --timeout=…` in einer
   **Restart-Schleife** und `schedule:run` im **60-s-Takt** (ein fehlgeschlagener
   Lauf verhindert den nächsten nicht);
3. endet mit `exec php-fpm -F` — php-fpm wird PID 1, die Schleifen bleiben Kinder.

Der Healthcheck (`deployment/backend-healthcheck.sh`) verlangt **FPM +
Supervisor + Worker + Scheduler** als laufend: ein toter Worker macht den
Container ungesund, während der Supervisor ihn neu startet. Er liest den
Prozess-Status aus `/proc` und lehnt Zombies ab (`kill -0` meldet einen Zombie
fälschlich als lebend — Position 21). `schedule:run` löst die in
`backend/routes/console.php` registrierten Commands `allocation:run` (stündlich)
und `reminders:send` (täglich) aus.

Dead-Letter-Betrieb (die `failed_jobs`-Tabelle existiert seit Projektbeginn):

```bash
docker compose -f deployment/docker-compose.yml --profile prod exec backend \
    php artisan queue:failed
docker compose -f deployment/docker-compose.yml --profile prod exec backend \
    php artisan queue:retry <id|all>
```

### Zwei Flags, beide Default AUS

- **`RUN_SEEDER`** — `DatabaseSeeder` legt in `production` den globalen
  Super-Admin an, sobald `ADMIN_EMAIL` **und** `ADMIN_PASSWORD` gesetzt sind.
  Ein Seeder-Lauf bei jedem Deploy ist deshalb **nicht** Voreinstellung:

  ```bash
  RUN_SEEDER=true ADMIN_EMAIL=… ADMIN_PASSWORD=… \
      docker compose -f deployment/docker-compose.yml --profile prod up -d
  ```

- **`RUN_CACHE_WARMUP`** — schreibt `php artisan config:cache` in das geteilte
  Volume `app_cache` (einziges Verzeichnis, in dem ein Deploy-Artefakt den
  One-shot-Container überleben muss). **Folge:** die Konfiguration ist danach ein
  Schnappschuss der Deploy-Umgebung — jede ENV-Änderung braucht ein Re-Deploy
  oder ein manuelles `php artisan config:clear`. Ohne das Flag läuft
  `config:clear`, damit eine einmal aktivierte und wieder abgeschaltete
  Warmup-Option nicht stale nachwirkt. `route:cache`/`view:cache` sind bewusst
  nicht dabei (Closure in `routes/web.php` bzw. Ablage im containerlokalen
  `storage/framework/views`) — Begründung in `deployment/entrypoint.sh`.

### Manuelle Befehle (Escape-Hatch)

Alles, was der Deploy-Schritt tut, geht auch von Hand — nützlich für einen
Rollback oder wenn der `migrate`-Container bereits existiert:

```bash
docker compose -f deployment/docker-compose.yml --profile prod exec backend \
    php artisan migrate --force
docker compose -f deployment/docker-compose.yml --profile prod exec backend \
    php artisan storage:link
docker compose -f deployment/docker-compose.yml --profile prod exec backend \
    php artisan db:seed --force          # nur bewusst: legt den Super-Admin an
```

Caddy läuft laut Go-Live-Plan auf dem **Host** (`~/dev/caddyfile/Caddyfile`);
`backend` veröffentlicht deshalb nur `127.0.0.1:9000`, niemals `0.0.0.0` —
FastCGI ist unauthentifiziert. Details in `deployment/docker-compose.yml` und
`features/03-caddy-brand-files.md`.

## Tests

```bash
# Backend (SQLite :memory:, kein DB-Container nötig)
cd backend && php artisan test

# Frontend Unit (Vitest)
cd frontend && pnpm test:run

# Frontend E2E Smoke (Playwright; Backend + `pnpm dev` laufen müssen)
cd frontend && pnpm test:e2e:smoke

# Frontend Lint + Build
cd frontend && pnpm lint:fix && pnpm build
```

## Stack

- **Backend:** Laravel 13 · PHP 8.5 · php-open-source-saver/jwt-auth ·
  barryvdh/laravel-dompdf · symfony/html-sanitizer · Postgres (Dev/Prod) ·
  SQLite `:memory:` (Tests) · PHPUnit + paratest
- **Frontend:** React 19 · Vite · TypeScript (strict) · Tailwind CSS v4 ·
  daisyUI v5 · React Router v7 · SWR · react-hook-form + zod · Lingui ·
  Vitest + Playwright
- **Infra:** Docker (Postgres, Mailpit), GitHub Actions (Base-Image-Build,
  CI)

## Policy

Betriebs- und Entwicklungsregeln: `AGENTS.md` (Rollen, Definition of Done,
Test-/Tag-Policy) + `AGENTS.todo.md` (aktuelle TODOs).
