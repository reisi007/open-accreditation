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
deployment/  Dockerfile (PHP-FPM, pgsql) + docker-compose (Postgres + Mailpit)
features/    Dauerhafter SOLL-Zustand (Multi-Tenancy, Domain-Model)
```

## Lokales Setup

Voraussetzungen: PHP 8.5 (z. B. via [Laravel Herd](https://herd.laravel.com)),
Composer, Docker, Node.js + pnpm (`packageManager`-Pin in `frontend/package.json`).

Das Backend ist lokal über **Laravel Herd unter `https://accreditation.test/`**
erreichbar (Herd als Site auf das `backend/`-Verzeichnis zeigen; `APP_URL` ist
entsprechend gesetzt). Alternativ läuft es via `php artisan serve` unter
`http://localhost:8000` (Vite-Proxy in Schritt 3 bleibt gültig).

Mandanten-Domains (z. B. `bundesliga.test`) werden über den Host aufgelöst —
die entsprechenden Einträge müssen in `/etc/hosts` bzw. in Herd hinterlegt
werden, sonst 404t die `MandantContext`-Middleware. Der Primary-Mandant
`main` ist auf `accreditation.test` (+ `www`) und `localhost` gemappt.

```bash
# 1. Infra starten (Postgres 17 + Mailpit)
docker compose -f deployment/docker-compose.yml up -d

# 2. Backend-Setup (idempotent; anpassbar via ADMIN_EMAIL/ADMIN_PASSWORD)
bash scripts/e2e-up.sh
#    = composer install (falls fehlt) nicht enthalten — einmalig manuell:
#      cd backend && composer install
#    Der Script-Teil macht: .env aus .env.example, key:generate (falls leer),
#    jwt:secret (falls leer), migrate --force, db:seed --force.

# 3. Frontend
cd frontend && pnpm install && pnpm dev   # http://localhost:5173
```

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
