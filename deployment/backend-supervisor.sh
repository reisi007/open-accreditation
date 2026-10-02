#!/bin/sh
# ==========================================================================
# open-accreditation — Queue-Worker + Scheduler Supervisor
# --------------------------------------------------------------------------
# Übernommen aus `portal.reisinger.pictures/deployment/backend-supervisor.sh`
# (läuft dort produktiv) — MIT NAMENSANPASSUNG (PID-Pfade `portal-queue-*`
# → `accriditation-queue-*`, Worker-Name `accriditation-production`), weil
# eine Portal-Installation auf demselben Host sonst dieselben PID-Dateien
# benutzt und sich die Prozesse gegenseitig als „gesund" ausgeben würden.
#
# WARUM ES DIESES SKRIPT GIBT (Position 46):
# `Schedule::command()` in `routes/console.php` REGISTRIERT nur. Ohne einen
# Aufruf von `schedule:run` findet `allocation:run` (stündlich) und
# `reminders:send` (täglich) NIE statt — die Zusage in
# `features/accreditation/01-allocation-engine.md:408` („läuft in jeder
# Umgebung, auch dev") war bis dahin unwahr. Und ohne `queue:work` wird kein
# `ShouldQueue`-Job jemals abgearbeitet, `after_commit` hin oder her.
#
# Dies ist der EINE Ort, an dem beides läuft. Er endet mit `exec php-fpm -F`:
# der Supervisor wird PID 1, die Hintergrund-Schleifen bleiben dessen Kinder.
# `deployment/entrypoint.sh` (Modus `serve`) startet genau dieses Skript; der
# `backend`-Healthcheck (deployment/backend-healthcheck.sh) verlangt es UND
# FPM UND den Worker UND den Scheduler als laufend.
#
# DIE SECHS DETAILS (Portal-Referenz, `AGENTS.todo.md` Position 45/46): deren
# Weglassen führt genau den Fehler wieder ein, den dieses Skript verhindert.
#   1. `QUEUE_CONNECTION` muss `database` sein — sonst Abbruch beim Start.
#   2. `DB_QUEUE_CONNECTION` muss `DB_CONNECTION` entsprechen — sonst ist
#      `after_commit` bedeutungslos (Queue und DB in verschiedener Verbindung).
#   3. `QUEUE_WORKER_TIMEOUT` < `DB_QUEUE_RETRY_AFTER`, fail-closed — sonst
#      reserviert der Worker unter Last denselben hängenden Job doppelt.
#   4. Stale PIDs beim Start löschen — sonst lässt ein Container-Neustart eine
#      alte PID gesund aussehen, während der neue Supervisor noch startet.
#   5. Worker in einer Restart-Schleife mit PID-Marker — ein toter Worker darf
#      die Zustellung nicht stillstehen lassen.
#   6. `schedule:run` im 60-s-Takt, Fehler geloggt, Schleife läuft weiter —
#      ein fehlgeschlagener Lauf darf den nächsten nicht verhindern.
#
# FALLE, DIE DIESES SKRIPT BEWUSST NICHT IST (Portal-Runbook
# `29-production-operations-runbook.md:217`): ein „naked background
# `queue:work`" in einer Compose-Datei. Dort fehlen Restart, PID-Marker,
# Timeout-Grenze und Scheduler — der Prozess stirbt leise und nichts merkt es.
#
# POSIX `sh` (Debian → dash im Container): keine Bash-Syntax, keine Arrays.
# ==========================================================================
set -eu
umask 077

QUEUE_WORKER_TIMEOUT="${QUEUE_WORKER_TIMEOUT:-60}"
QUEUE_WORKER_RESTART_DELAY="${QUEUE_WORKER_RESTART_DELAY:-5}"
DB_QUEUE_RETRY_AFTER="${DB_QUEUE_RETRY_AFTER:-90}"
# Namensanpassung gegenüber Portal: `accriditation-*` statt `portal-*`.
QUEUE_SUPERVISOR_PID_FILE=/tmp/accriditation-queue-supervisor.pid
QUEUE_WORKER_PID_FILE=/tmp/accriditation-queue-worker.pid
SCHEDULER_PID_FILE=/tmp/accriditation-scheduler.pid

is_positive_integer() {
	case "$1" in
	'' | *[!0-9]*) return 1 ;;
	*) [ "$1" -gt 0 ] ;;
	esac
}

# --- Detail 3 (Teil 1): Zahlen validieren, bevor irgendetwas startet -----
if ! is_positive_integer "$QUEUE_WORKER_TIMEOUT"; then
	echo 'FATAL: QUEUE_WORKER_TIMEOUT muss eine positive ganze Zahl sein.' >&2
	exit 1
fi

if ! is_positive_integer "$QUEUE_WORKER_RESTART_DELAY"; then
	echo 'FATAL: QUEUE_WORKER_RESTART_DELAY muss eine positive ganze Zahl sein.' >&2
	exit 1
fi

if ! is_positive_integer "$DB_QUEUE_RETRY_AFTER"; then
	echo 'FATAL: DB_QUEUE_RETRY_AFTER muss eine positive ganze Zahl sein.' >&2
	exit 1
fi

# --- Detail 1: Queue-Treiber muss die DB sein ---------------------------
if [ "${QUEUE_CONNECTION:-}" != 'database' ]; then
	echo 'FATAL: QUEUE_CONNECTION muss im Produktions-Stack database sein.' >&2
	exit 1
fi

# --- Detail 2: Queue und DB in derselben Verbindung ---------------------
# Ohne diese Gleichheit schreibt ein `after_commit`-Job in eine ANDERE
# Verbindung als die Transaktion, die ihn ausgelöst hat — die Zusage „Status
# und Zustellung in derselben Transaktion" (AGENTS.todo.md Position 45) wäre
# dann nur auf dem Papier wahr.
if [ -z "${DB_CONNECTION:-}" ] || [ "${DB_QUEUE_CONNECTION:-}" != "${DB_CONNECTION:-}" ]; then
	echo 'FATAL: DB_QUEUE_CONNECTION muss DB_CONNECTION entsprechen.' >&2
	exit 1
fi

# --- Detail 3 (Teil 2): Timeout strikt kleiner als retry_after -----------
# Sonst kann der Worker denselben (hängenden) Job erneut reservieren, während
# der erste Versuch noch läuft: doppelte Zustellung. Fail-closed beim Start.
if [ "$QUEUE_WORKER_TIMEOUT" -ge "$DB_QUEUE_RETRY_AFTER" ]; then
	echo 'FATAL: QUEUE_WORKER_TIMEOUT muss kleiner als DB_QUEUE_RETRY_AFTER sein.' >&2
	exit 1
fi

# --- Detail 4: stale PIDs beim Start löschen ----------------------------
# Ein Container-Neustart darf nicht eine PID aus dem VORIGEN Container als
# „läuft noch" erscheinen lassen, während dieser Supervisor erst hochkommt.
rm -f "$QUEUE_SUPERVISOR_PID_FILE" "$QUEUE_WORKER_PID_FILE" "$SCHEDULER_PID_FILE"

# --- Detail 5: Worker in Restart-Schleife mit PID-Marker ----------------
# Der PID-Marker wird atomar geschrieben (tmp + mv), damit ein Leser nie eine
# halb geschriebene Datei sieht. Nach dem Ende des Workers wird er entfernt:
# der Healthcheck sieht in diesem Fenster keinen lebenden Worker und meldet
# den Container ungesund, bis der neue Worker läuft.
queue_supervisor_loop() {
	while :; do
		php artisan queue:work \
			--name=accriditation-production \
			--tries=3 \
			--timeout="$QUEUE_WORKER_TIMEOUT" &
		worker_pid=$!

		printf '%s\n' "$worker_pid" > "${QUEUE_WORKER_PID_FILE}.tmp"
		mv "${QUEUE_WORKER_PID_FILE}.tmp" "$QUEUE_WORKER_PID_FILE"

		worker_status=0
		wait "$worker_pid" || worker_status=$?
		rm -f "$QUEUE_WORKER_PID_FILE"

		if [ "$worker_status" -ne 0 ]; then
			echo "Queue worker exited with status ${worker_status}; restarting in ${QUEUE_WORKER_RESTART_DELAY}s." >&2
		fi

		sleep "$QUEUE_WORKER_RESTART_DELAY"
	done
}

# --- Detail 6: schedule:run im 60-s-Takt, Fehler beenden die Schleife NICHT
scheduler_loop() {
	while :; do
		if ! php artisan schedule:run; then
			echo 'Scheduler run failed; the next run will be attempted in 60s.' >&2
		fi

		sleep 60
	done
}

queue_supervisor_loop &
queue_supervisor_pid=$!
printf '%s\n' "$queue_supervisor_pid" > "${QUEUE_SUPERVISOR_PID_FILE}.tmp"
mv "${QUEUE_SUPERVISOR_PID_FILE}.tmp" "$QUEUE_SUPERVISOR_PID_FILE"

scheduler_loop &
scheduler_pid=$!
printf '%s\n' "$scheduler_pid" > "${SCHEDULER_PID_FILE}.tmp"
mv "${SCHEDULER_PID_FILE}.tmp" "$SCHEDULER_PID_FILE"

echo 'Queue supervisor and scheduler started.'

# Zuletzt: FPM wird PID 1. Die beiden Schleifen bleiben als dessen Kinder am
# Leben. `schedule:run` löst die in `routes/console.php` registrierten
# Commands (`allocation:run` stündlich, `reminders:send` täglich) aus; genau
# hier wird die Zusage „läuft in jeder Umgebung" (dev eingeschlossen, soweit
# dev diesen Supervisor startet) erst wahr.
exec php-fpm -F
