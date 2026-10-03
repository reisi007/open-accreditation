#!/usr/bin/env bash
#
# open-accreditation — lokaler Queue-Worker + Scheduler (Dev)
#
# WARUM ES DIESES SKRIPT GIBT
# `features/accreditation/01-allocation-engine.md:408` sagt zu, dass
# `allocation:run` „in jeder Umgebung (auch dev)" läuft — eine abgelaufene
# Akkreditierung muss unabhängig von der Umgebung alloziert werden. Im
# Prod-Stack leistet das `deployment/backend-supervisor.sh` (im Image, gestartet
# über `deployment/entrypoint.sh serve`, abgesichert durch den Healthcheck).
#
# WARUM DAS NICHT IM DEV-COMPOSE LÄUFT
# Das Dev-Backend ist KEIN Container. `scripts/e2e-up.sh` startet nur die Infra
# (Postgres + Mailpit), die App läuft host-native über `php artisan serve`
# (README „Lokale Entwicklung"). Ein Container-Supervisor kann keinen
# host-nativen Prozess besitzen, und ein Bind-Mount des Checkouts in das
# UID-33-Image würde auf Linux-Dev-Hosts `storage/`-Schreibzugriffe brechen.
# Dieses Skript schliesst dieselbe Lücke host-native.
#
# WAS ES TUT (bewusst identisch zum Prod-Takt)
#   * `queue:work --tries=5 --timeout=60` in einer Restart-Schleife mit
#     PID-Marker (ein toter Worker lässt die Zustellung nicht stillstehen).
#     `--tries` ist der BODEN für Jobs OHNE eigenen Deckel, nicht die
#     Obergrenze: `SendMandantMail` trägt `$tries = 5` im Payload und der
#     Job-Deckel gewinnt (gemessen: mit `--tries=1` wurde der Job NICHT nach
#     dem ersten Versuch dead-letteret). Beide Zahlen stehen auf 5, damit die
#     Angabe nicht irreführt.
#   * `schedule:run` im 60-s-Takt; ein fehlgeschlagener Lauf verhindert den
#     nächsten NICHT.
# Die Konfiguration kommt aus `backend/.env` (Laravel liest sie selbst). Für
# `QUEUE_CONNECTION`/`DB_QUEUE_CONNECTION` trägt das Argument der fehlenden
# Guards: der Dev-Pfad hat keine injezierte Compose-ENV, die von der `.env`
# abweichen könnte.
#
# Für `CACHE_STORE` TRÄGT DIESES ARGUMENT NICHT — und der Unterschied ist
# nicht kosmetisch. Das Dev-Setup setzt den Store selbst auf `array`
# (`scripts/e2e-up.sh`, unbedingt, für Rate-Limiter-Determinismus), und
# dieses Skript ist genau der Pfad, auf dem ein ECHTER Worker neben
# `php artisan serve` läuft: der Idempotenz-Claim aus
# `App\Jobs\SendMandantMail` ist dort prozesslokal und der
# Doppelzustellungsschutz faktisch unwirksam.
#
# WARUM TROTZDEM KEIN FAIL-CLOSED-GUARD (wie „Detail 2b" im Prod-Supervisor,
# `deployment/backend-supervisor.sh`): dieser Stack ist der dokumentierte
# Dev-Weg (README „Worker & Scheduler in Dev"), und der Store ist hier
# ABSICHT, nicht Versehen. Ein Abbruch beim Start würde den einzigen
# dokumentierten Weg zu `allocation:run`/`reminders:send` in dev sofort
# unbenutzbar machen — und ein Dev-Stack, der sich weigert zu starten,
# produziert denselben stillen Zustellungsstillstand, den der Prod-Guard
# verhindern soll. Die Warnung beim Start BENENNT den Zustand und was er
# kostet, statt den Betrieb zu verweigern; wer ihn nicht will, setzt
# `CACHE_STORE=database` in `backend/.env`.
#
# USAGE
#   bash scripts/dev-worker.sh      # läuft im Vordergrund, Ctrl-C beendet
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"

cd "$BACKEND_DIR"

if [ ! -f .env ]; then
    echo "ERROR: backend/.env fehlt — zuerst 'bash scripts/e2e-up.sh' ausführen." >&2
    exit 1
fi

# NICHT-FATALER HINWEIS (siehe Kopfkommentar): ein prozesslokaler Cache-Store
# macht den Idempotenz-Claim des Mail-Jobs unwirksam.
#
# Die AUFLÖSUNG (ENV vor `.env` vor `config/cache.php`-Default `database`) war
# richtig — und ist es unverändert. Das AUSLESEN der `.env` war es nicht
# (Befund 2026-10-03): `grep -E '^CACHE_STORE='` erkennt nur die NULLTE Spalte
# und kein `export`. phpdotenv akzeptiert beides, also blieb
# `  CACHE_STORE=array` und `export CACHE_STORE=array` unerkannt, das Skript
# fiel still auf `database` zurück — und die Warnung blieb für einen Stack aus,
# dessen Claim nachweislich prozesslokal ist. Ein Hinweis, der die falsche
# Antwort gibt, ist schlimmer als keiner.
#
# `dotenv_value` liest deshalb dieselben Formen, die Laravel liest: optionales
# `export`, Leerzeichen vor dem Schlüssel und um `=`, Quotes um den Wert, ein
# Inline-Kommentar, und — wie phpdotenv — die LETZTE Zu gewinnt. Erkennt es
# nichts, liefert es nichts: der Aufrufer fällt auf `database` zurück und sagt
# nichts Falsches (leer ≠ `array`, und `array` ist der Fall, der die Warnung
# verdient).
dotenv_value() {
    local file="$1" key="$2" line value='' rest

    [ -f "$file" ] || return 0

    while IFS= read -r line || [ -n "$line" ]; do
        # führende Leerzeichen, optionales `export`
        line="${line#"${line%%[![:space:]]*}"}"
        if [ "${line#export}" != "$line" ]; then
            line="${line#export}"
            line="${line#"${line%%[![:space:]]*}"}"
        fi

        [ "${line#"$key"}" != "$line" ] || continue

        rest="${line#"$key"}"
        rest="${rest#"${rest%%[![:space:]]*}"}"
        [ "${rest#=}" != "$rest" ] || continue

        value="${rest#=}"
        value="${value#"${value%%[![:space:]]*}"}"

        case "$value" in
            '"'*'"') value="${value#\"}"; value="${value%%\"*}" ;;
            "'"*"'") value="${value#\'}"; value="${value%%\'*}" ;;
            *' #'*) value="${value%% #*}" ;;
        esac

        # Leerzeichen rechts abschneiden (unquoted Werte sind bei phpdotenv getrimmt)
        value="${value%"${value##*[![:space:]]}"}"
    done < "$file"

    printf '%s' "${value-}"
}

# Der Start läuft bewusst weiter: dieser Stack ist der dokumentierte Dev-Weg, und
# der Prod-Guard (`deployment/backend-supervisor.sh`, Detail 2b) würde ihn sofort
# abbrechen.
EFFECTIVE_CACHE_STORE="${CACHE_STORE:-$(dotenv_value .env CACHE_STORE)}"
EFFECTIVE_CACHE_STORE="${EFFECTIVE_CACHE_STORE:-database}"

case "$EFFECTIVE_CACHE_STORE" in
    database | redis | memcached | dynamodb) ;;
    *)
        echo "NOTE: CACHE_STORE=${EFFECTIVE_CACHE_STORE} is PER-PROCESS — the idempotency" >&2
        echo "      claim in App\\Jobs\\SendMandantMail cannot cross the process boundary" >&2
        echo "      (php artisan serve vs. this worker), so duplicate delivery is NOT" >&2
        echo "      suppressed in this dev stack. Expected here: scripts/e2e-up.sh pins" >&2
        echo "      'array' for rate-limiter determinism. Production refuses a" >&2
        echo "      non-shared store fail-closed. Set CACHE_STORE=database in" >&2
        echo "      backend/.env to get the guard back." >&2
        ;;
esac

WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-60}"
WORKER_RESTART_DELAY="${QUEUE_WORKER_RESTART_DELAY:-5}"

PID_DIR="${TMPDIR:-/tmp}"
SUPERVISOR_PID_FILE="$PID_DIR/accriditation-dev-queue-supervisor.pid"
WORKER_PID_FILE="$PID_DIR/accriditation-dev-queue-worker.pid"
SCHEDULER_PID_FILE="$PID_DIR/accriditation-dev-scheduler.pid"

# Stale PIDs eines früheren Laufs entfernen, damit der Zustand eindeutig ist.
rm -f "$SUPERVISOR_PID_FILE" "$WORKER_PID_FILE" "$SCHEDULER_PID_FILE"

queue_supervisor_loop() {
    while :; do
        php artisan queue:work \
            --name=accriditation-dev \
            --tries=5 \
            --timeout="$WORKER_TIMEOUT" &
        worker_pid=$!

        printf '%s\n' "$worker_pid" > "${WORKER_PID_FILE}.tmp"
        mv "${WORKER_PID_FILE}.tmp" "$WORKER_PID_FILE"

        worker_status=0
        wait "$worker_pid" || worker_status=$?
        rm -f "$WORKER_PID_FILE"

        if [ "$worker_status" -ne 0 ]; then
            echo "Queue worker exited with status ${worker_status}; restarting in ${WORKER_RESTART_DELAY}s." >&2
        fi

        sleep "$WORKER_RESTART_DELAY"
    done
}

scheduler_loop() {
    while :; do
        if ! php artisan schedule:run; then
            echo 'Scheduler run failed; the next run will be attempted in 60s.' >&2
        fi

        sleep 60
    done
}

queue_supervisor_loop &
supervisor_pid=$!
printf '%s\n' "$supervisor_pid" > "$SUPERVISOR_PID_FILE"

scheduler_loop &
scheduler_pid=$!
printf '%s\n' "$scheduler_pid" > "$SCHEDULER_PID_FILE"

echo 'Dev worker + scheduler started (Ctrl-C to stop).'
echo "  PID files: $PID_DIR/accriditation-dev-{queue-supervisor,queue-worker,scheduler}.pid"

cleanup() {
    kill "$supervisor_pid" "$scheduler_pid" 2>/dev/null || true
    if [ -f "$WORKER_PID_FILE" ]; then
        kill "$(cat "$WORKER_PID_FILE")" 2>/dev/null || true
    fi
    rm -f "$SUPERVISOR_PID_FILE" "$WORKER_PID_FILE" "$SCHEDULER_PID_FILE"
}
trap 'cleanup; exit 0' INT TERM

# Auf die Hintergrund-Schleifen warten; Ctrl-C triggert `cleanup`.
wait
