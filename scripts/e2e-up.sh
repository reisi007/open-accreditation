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
# This script is the single documented local entry point (README.md,
# backend/AGENTS.md) and owns the two things a raw `docker compose` call
# cannot do on a clean checkout (WF-2-a):
#
#   1. `--env-file deployment/dev.env`. `deployment/docker-compose.yml` makes
#      `APP_KEY`, `DB_USERNAME` and `DB_PASSWORD` mandatory via `${VAR:?…}`
#      (R-D8), and Docker Compose expands variables while LOADING a file —
#      before profile selection, so even a plain `up -d` trips the `:?` of the
#      `prod`-only service. `dev.env` is a committed, DEV-ONLY file of public
#      placeholders that feeds exactly that expansion. A `-f` override could
#      not do this: the base file is interpolated before the merge, so its
#      `:?` still fires. A local `deployment/.env` (gitignored), if present,
#      is appended as a FURTHER `--env-file` and wins over `dev.env` (the last
#      `--env-file` wins — verified); the shell environment wins over both.
#   2. `--profile mail`. `mailpit` sits behind that profile, so a plain `up -d`
#      starts `db` only and the E2E mail flow (MAIL_HOST=127.0.0.1, SMTP 1025 /
#      UI 8025) would fail with an opaque connection error. The script asserts
#      that mailpit is actually running before it returns.
#
# Equivalent manual invocation:
#   docker compose --env-file deployment/dev.env \
#                  -f deployment/docker-compose.yml --profile mail up -d
#
# The seeder reads ADMIN_EMAIL/ADMIN_PASSWORD from the real environment
# (takes precedence over .env) or falls back to the .env values.
#
# Rate-Limiter-Determinismus (P3e-B5 / WP-9-D5):
# Der Cache-Store wird hier auf `array` gesetzt (weiter unten per sed, exakt
# wie im CI-e2e-Job). Named rate-limiter counters (login/register/apply/...)
# leben sonst im DB-Cache-Store (config/cache.php, Default
# CACHE_STORE=database) und persistieren ueber Runs hinweg (beobachtet: bis zu
# 7 Tage). Back-to-back-E2E-/Screenshot-Laeufe gegen dasselbe persistente
# dev-Postgres laufen dann trotz "frischem" Suite-Start in Login-Throttle-429s.
# Mit `array` ist der RateLimiter pro Request zustandslos — es gibt also
# KEINEN Limiter-State, der zwischen Runs erhalten bleibt, und das manuelle
# `php artisan cache:clear` vor jedem Lauf entfaellt. Der CI-e2e-Job macht
# genau dasselbe (`sed` setzt CACHE_STORE=array in .env), aus demselben Grund.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
COMPOSE_FILE="$ROOT_DIR/deployment/docker-compose.yml"
DEV_ENV_FILE="$ROOT_DIR/deployment/dev.env"
LOCAL_ENV_FILE="$ROOT_DIR/deployment/.env"

ADMIN_EMAIL="${ADMIN_EMAIL:-admin@example.com}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-admin}"

# Wait (max ~30 s) until a TCP connect to $host:$port succeeds. Uses bash's
# /dev/tcp so no extra tooling (curl/nc) is required.
wait_for_tcp() {
    local host="$1" port="$2"
    local _
    for _ in $(seq 1 30); do
        if (exec 3<>"/dev/tcp/${host}/${port}") 2>/dev/null; then
            exec 3<&- 2>/dev/null || true
            exec 3>&- 2>/dev/null || true
            return 0
        fi
        sleep 1
    done
    return 1
}

if [ ! -f "$DEV_ENV_FILE" ]; then
    echo "ERROR: $DEV_ENV_FILE is missing (it is committed — run git checkout)." >&2
    exit 1
fi

# `--profile mail` is REQUIRED: mailpit is behind that profile and the E2E mail
# flow needs it. The `db` service has no profile, so it always comes up.
COMPOSE=(docker compose --env-file "$DEV_ENV_FILE" -f "$COMPOSE_FILE")
if [ -f "$LOCAL_ENV_FILE" ]; then
    COMPOSE+=(--env-file "$LOCAL_ENV_FILE")
fi
COMPOSE+=(--profile mail)

echo "==> Starting infrastructure (Postgres + Mailpit)..."
"${COMPOSE[@]}" up -d

echo "==> Waiting for Postgres to become ready..."
# Probe with the credentials the container itself was started with, so a custom
# `deployment/.env` or exported DB_* values do not break the readiness check.
READY=0
for _ in $(seq 1 60); do
    if "${COMPOSE[@]}" exec -T db sh -c 'pg_isready -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null 2>&1; then
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

# Without `--profile mail` only `db` comes up and the mail flow breaks later
# with an opaque SMTP connection error — so wait for the endpoint the app
# actually talks to. `docker compose ps` is NOT usable for this: it reports the
# project's RUNNING containers, so a mailpit left over from an earlier run
# would mask a missing profile (verified). A TCP connect to the published port
# proves the dependency itself.
if ! wait_for_tcp 127.0.0.1 1025 || ! wait_for_tcp 127.0.0.1 8025; then
    echo "ERROR: mailpit is not reachable on 127.0.0.1:1025/8025 — the 'mail' compose profile is required for the E2E mail flow." >&2
    exit 1
fi
echo "    Mailpit is up (SMTP 127.0.0.1:1025, UI http://localhost:8025)."

cd "$BACKEND_DIR"

echo "==> Preparing backend environment..."
if [ ! -f .env ]; then
    cp .env.example .env
    echo "    Created .env from .env.example."
fi

# Rate-Limiter-Determinismus (siehe Kopfkommentar): CACHE_STORE=array,
# gespiegelt vom CI-e2e-Job. Unkonditional, damit auch ein bereits vorhandenes
# .env aus einem frueheren `database`-Lauf korrigiert wird.
sed -i.bak -E -e 's|^CACHE_STORE=.*|CACHE_STORE=array|' .env && rm -f .env.bak
grep -q '^CACHE_STORE=array$' .env

# The app talks to the same database the `db` container was seeded with. Warn
# (without aborting) when a local `deployment/.env` makes the two disagree —
# otherwise `migrate` fails with an opaque Postgres authentication error.
if [ -f "$LOCAL_ENV_FILE" ]; then
    for key in DB_DATABASE DB_USERNAME DB_PASSWORD; do
        local_value="$(grep -E "^${key}=" "$LOCAL_ENV_FILE" | tail -n 1 | cut -d= -f2- || true)"
        backend_value="$(grep -E "^${key}=" .env | tail -n 1 | cut -d= -f2- || true)"
        if [ -n "$local_value" ] && [ "$local_value" != "$backend_value" ]; then
            echo "    WARNING: $key differs between deployment/.env ('$local_value') and backend/.env ('$backend_value')." >&2
            echo "    WARNING: align them or the migrate below cannot authenticate." >&2
        fi
    done
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
echo "  Worker:       bash scripts/dev-worker.sh  (NOT started by this script)"
echo ""
# No queue worker, and that is a NAMED decision, not an omission:
echo "  NOTE: this script starts NO queue worker, and QUEUE_CONNECTION stays"
echo "        'database' (from .env.example) — so nothing processes the 'jobs'"
echo "        table, and every dispatched mail just sits there. Running a"
echo "        mail-dependent Playwright spec in that state waits out the full"
echo "        15 s Mailpit timeout ('no message for ... within 15000ms')."
echo "        Either run 'bash scripts/dev-worker.sh' in a second terminal"
echo "        (the documented dev path, README 'Worker & Scheduler in Dev'),"
echo "        or set QUEUE_CONNECTION=sync in backend/.env like the CI E2E job"
echo "        does (delivery inline, no worker — the price: after_commit is"
echo "        only defined on the 'database' connection)."
