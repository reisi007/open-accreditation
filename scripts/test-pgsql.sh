#!/usr/bin/env bash
#
# Postgres-Portabilitäts-Gate lokal fahren (AGENTS.md §2).
#
# Der CI-Job `backend-pgsql` in `.github/workflows/ci.yml` fährt die Suite gegen
# echtes PostgreSQL 17. Dieselbe Prüfung lokal:
#
#   bash scripts/test-pgsql.sh
#   bash scripts/test-pgsql.sh --filter AllocationAtomicityTest
#
# Warum ein eigenes Skript statt eines `DB_CONNECTION=pgsql php artisan test`:
#   * Der Ziel-DB-Name ist fix auf einen **Wegwerf**-Namen gesetzt und die DB
#     wird vorher frisch erzeugt. `RefreshDatabase` führt `migrate:fresh` aus —
#     gegen die Dev-Datenbank wäre das ein Datenverlust.
#   * Es braucht KEINEN Docker-Start und kein `psql`-Binary: das Anlegen der
#     Wegwerf-DB läuft über PDO in der PHP-Version, mit der die Suite ohnehin
#     läuft. Ein per `docker compose … up` gestarteter Postgres und ein
#     nativ installierter funktionieren beide.
#
# Warum ENV statt eines zweiten `phpunit.xml`: `backend/phpunit.xml:61-63` pinnt
# `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:` OHNE `force="true"`, und
# `vendor/phpunit/phpunit/src/TextUI/Configuration/PhpHandler.php:160-168` setzt
# eine `<env>`-Variable nur, wenn `force` gesetzt ist oder sie in der
# Prozess-Umgebung noch nicht existiert. Die ENV gewinnt also gegen den Pin.
# Laravels Dotenv-Repository ist immutable und kann die Variablen weder
# überschreiben noch von der `.env` zurückholn.
#
# Bewusst SERIELL, niemals `--parallel`: paratest gibt jedem Worker eine eigene
# Test-DB, und die SQLite-Isolationsannahme der Suite (ein Prozess, eine
# In-Memory-DB, `backend/AGENTS.md`) trägt auf einer geteilten Postgres-DB nicht.
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"

# Zugangsdaten des laufenden Postgres. Defaults entsprechen `deployment/dev.env`
# (dem Compose-`db`-Service) und der `.env.example`; überschreibbar per ENV.
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"
DB_USERNAME="${DB_USERNAME:-accriditation}"
DB_PASSWORD="${DB_PASSWORD:-accriditation}"

# Admin-DB für CREATE/DROP DATABASE. Die Wegwerf-DB selbst ist bewusst NICHT
# `accriditation` (das ist die Dev-Datenbank aus deployment/dev.env).
ADMIN_DB="${ADMIN_DB:-postgres}"
GATE_DB="${GATE_DB:-accriditation_test}"

if [ "$GATE_DB" = "accriditation" ]; then
  echo "ERROR: GATE_DB darf nicht 'accriditation' sein — das ist die Dev-Datenbank." >&2
  echo "       migrate:fresh würde sie leeren." >&2
  exit 1
fi

PASSTHROUGH=("$@")

# Die Engine-Wahl, EINMAL definiert und an Guard UND Testlauf gereicht. Beide
# müssen dieselbe sehen, sonst prüft der Guard eine andere Umgebung als der Lauf.
# Reihenfolge = Namen aus backend/config/database.php (pgsql-Connection, Z. 87-100).
# `DB_URL` absichtlich weggelassen: der Leerwert aus phpunit.xml:63 ist ein
# No-op (ConfigurationUrlParser.php:43 — `if (! $url) return $config;`).
GATE_ENV=(
  DB_CONNECTION=pgsql
  DB_HOST="$DB_HOST"
  DB_PORT="$DB_PORT"
  DB_DATABASE="$GATE_DB"
  DB_USERNAME="$DB_USERNAME"
  DB_PASSWORD="$DB_PASSWORD"
)

echo "==> Gate-Datenbank bereitstellen: $GATE_DB auf $DB_HOST:$DB_PORT"
# CREATE/DROP über PDO, damit weder psql noch docker nötig sind. `DROP ... IF
# EXISTS` + `CREATE` ergibt garantiert eine leere DB — genau der Zustand, den
# `migrate:fresh` ohnehin herstellt.
env \
  DB_HOST="$DB_HOST" \
  DB_PORT="$DB_PORT" \
  DB_USERNAME="$DB_USERNAME" \
  DB_PASSWORD="$DB_PASSWORD" \
  DB_ADMIN_DATABASE="$ADMIN_DB" \
  DB_GATE_DATABASE="$GATE_DB" \
  php -r '
$pdo = new PDO(
    sprintf("pgsql:host=%s;port=%s;dbname=%s", getenv("DB_HOST"), getenv("DB_PORT"), getenv("DB_ADMIN_DATABASE")),
    getenv("DB_USERNAME"),
    getenv("DB_PASSWORD"),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec("DROP DATABASE IF EXISTS \"".getenv("DB_GATE_DATABASE")."\"");
$pdo->exec("CREATE DATABASE \"".getenv("DB_GATE_DATABASE")."\"");
'

echo "==> Gegenprobe: aufgelöster Driver muss pgsql sein"
# Sonst wäre der Lauf ein zweiter SQLite-Lauf, der grün ist und nichts prüft.
# Dieselbe Prüfung wie im CI-Job `backend-pgsql` — gemeinsames Skript, damit
# CI und lokal nicht auseinanderlaufen können.
#
# WICHTIG: bekommt dieselben GATE_ENV wie der Testlauf. Ohne sie prüfte der
# Guard die Umgebung des Entwicklers — im Dev-Checkout steht `DB_CONNECTION=pgsql`
# in der `.env`, der Guard wäre also grün, obwohl die Override-Mechanik kaputt
# wäre. Er muss die Engine-Wahl dieses Laufs prüfen, nicht die der `.env`.
(cd "$BACKEND_DIR" && env "${GATE_ENV[@]}" php ../scripts/assert-db-driver.php pgsql)

echo "==> Suite auf Postgres (seriell)"
cd "$BACKEND_DIR"

# `env` statt `export`: die Variablen gelten nur für den Testprozess, die
# `.env` des Checkouts wird nicht angetastet.
set +e
env "${GATE_ENV[@]}" php artisan test --no-ansi "${PASSTHROUGH[@]+"${PASSTHROUGH[@]}"}"
STATUS=$?
set -e

if [ "$STATUS" -ne 0 ]; then
  echo ""
  echo "FAILED (exit $STATUS) — laut AGENTS.md §2 ist das ein Portabilitätsbefund,"
  echo "kein Skew: die Suite ist auf SQLite grün, auf Postgres nicht. Bitte den"
  echo "Befund beheben statt den Lauf zu entschärfen. Details siehe AGENTS.todo.md."
fi

exit "$STATUS"
