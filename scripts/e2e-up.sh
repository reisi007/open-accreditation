#!/usr/bin/env bash
#
# Idempotent local development setup for open-accreditation.
# Starts the Docker infra (Postgres + Mailpit) and prepares the Laravel
# backend (env, APP_KEY, JWT_SECRET, migrate + seed). Safe to re-run.
#
# Usage:
#   bash scripts/e2e-up.sh
#   ADMIN_EMAIL=dev@example.com ADMIN_PASSWORD=secret bash scripts/e2e-up.sh
#
# The seeder reads ADMIN_EMAIL/ADMIN_PASSWORD from the real environment
# (takes precedence over .env) or falls back to the .env values.
#
# Rate-Limiter-Determinismus (P3e-B5):
# Named rate-limiter counters (login/register/apply/...) persist in the DB
# cache store (config/cache.php, default CACHE_STORE=database) — observed
# persistence up to 7 days. Back-to-back local E2E / screenshot runs against
# the persistent dev Postgres can therefore hit login-throttle 429s although
# each suite run starts "fresh". For deterministic runs, clear the cache
# BEFORE launching the E2E suite:
#
#   cd backend && php artisan cache:clear
#
# The CI e2e job is unaffected: it migrates a fresh database per job (empty
# cache table), so no limiter state carries over.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"

echo "==> Starting infrastructure (Postgres + Mailpit)..."
docker compose -f "$COMPOSE_FILE" up -d

echo "==> Waiting for Postgres to become ready..."
READY=0
for _ in $(seq 1 60); do
    if docker compose -f "$COMPOSE_FILE" exec -T db pg_isready -U accriditation -d accriditation >/dev/null 2>&1; then
        READY=1
        break
    fi
    sleep 1
done
if [ "$READY" -ne 1 ]; then
    echo "ERROR: Postgres did not become ready in time." >&2
    exit 1
fi
echo "    Postgres is ready."

cd "$BACKEND_DIR"

echo "==> Preparing backend environment..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "    Created .env from .env.example."
fi

if grep -qE '^APP_KEY=.+' .env; then
    echo "    APP_KEY already set — skipping key:generate."
else
    echo "    APP_KEY empty — generating..."
    php artisan key:generate --force
fi

if grep -qE '^JWT_SECRET=.+' .env; then
    echo "    JWT_SECRET already set — skipping jwt:secret."
else
    echo "    JWT_SECRET empty — generating..."
    php artisan jwt:secret --force
fi

echo "==> Migrating (idempotent)..."
php artisan migrate --force

echo "==> Seeding (idempotent, admin via firstOrCreate)..."
php artisan db:seed --force

echo ""
echo "Setup complete."
echo "  Admin login:  $ADMIN_EMAIL / $ADMIN_PASSWORD (dev default, see .env)"
echo "  Backend:      http://localhost:8000"
echo "  Mailpit UI:   http://localhost:8025"
echo "  Next:         cd frontend && pnpm install && pnpm dev  (http://localhost:5173)"
