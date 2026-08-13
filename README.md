# open-accriditation

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

## Lokales Setup (Backend)

Voraussetzungen: PHP 8.5 (z. B. via [Laravel Herd](https://herd.laravel.com)),
Composer, Docker.

```bash
# 1. Infra starten (Postgres 17 + Mailpit)
docker compose -f deployment/docker-compose.yml up -d

# 2. Backend installieren
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan jwt:secret

# 3. DB migrieren (immer seeden — ohne Seed kein Admin-User)
php artisan migrate --seed
```

Start: `php artisan serve` (Mail-UI: http://localhost:8025).

## Tests

```bash
cd backend && php artisan test
```

Tests laufen vollständig auf **SQLite `:memory:`** (siehe `phpunit.xml`) —
kein DB-Container nötig. Parallele Läufe via `php artisan test --parallel`
(paratest) sind isoliert.

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
