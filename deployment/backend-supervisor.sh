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
# Dazu kommt ein SIEBTER, aus demselben Fail-closed-Grund, aber nicht aus dem
# Portal: `CACHE_STORE` muss ein geteilter Store sein (Detail 2b) — der
# Idempotenz-Claim des Mail-Jobs und der `withoutOverlapping()`-Lock des
# Schedulers liegen beide darin, und prozesslokal wären sie beide still unwirksam.
#   1. `QUEUE_CONNECTION` muss `database` sein — sonst Abbruch beim Start.
#   2. `DB_QUEUE_CONNECTION` muss `DB_CONNECTION` entsprechen — sonst ist
#      `after_commit` bedeutungslos (Queue und DB in verschiedener Verbindung).
#   3. `QUEUE_WORKER_TIMEOUT` < `DB_QUEUE_RETRY_AFTER`, fail-closed — sonst
#      reserviert der Worker unter Last denselben hängenden Job doppelt.
#   4. Stale PIDs beim Start löschen — sonst lässt ein Container-Neustart eine
#      alte PID gesund aussehen, während der neue Supervisor noch startet.
#   5. Worker in einer Restart-Schleife mit PID-Marker — ein toter Worker darf
#      die Zustellung nicht stillstehen lassen.
#   6. `schedule:run` im 60-s-Takt, Schleife läuft weiter — ein fehlgeschlagener
#      Lauf darf den nächsten nicht verhindern.
#
#      GEMESSEN 2026-10-02, wichtig: der `if !`-Zweig darunter kann für einen
#      fehlgeschlagenen TASK nie feuern. `ScheduleRunCommand::runEvent()` wirft
#      zwar, `handle()` faengt es aber und kehrt normal zurueck — `schedule:run`
#      endet also mit Exit 0 (belegt in
#      `tests/Feature/ScheduledTaskObservabilityTest`). Die Sichtbarkeit laeuft
#      deshalb ueber `App\Support\ScheduledTaskObserver` (Heartbeat `info` /
#      Fehler `error` im Anwendungslog, geschrieben im Scheduler-Prozess selbst)
#      und ueber das `Log::info`/`Log::error` der Commands. Der Zweig ist trotzdem
#      nicht entfernt: er faengt den Fall, in dem der Scheduler-PROZESS selbst
#      stirbt — dann traegt nur noch sein Exit-Code die Information.
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

# --- Detail 2b: der Idempotenz-Claim braucht einen GETEILTEN Store -------
# (kein Portal-Detail, sondern unser siebtes; `docker-compose.yml:271-274`
# begründet denselben Wert bereits für den Scheduler-Lock.)
#
# `App\Jobs\SendMandantMail` verhindert Doppelzustellung mit einem
# test-and-set Claim auf dem Default-Cache-Store
# (`Cache::add('mail-delivery:{deliveryId}', true, CLAIM_TTL_SECONDS)`). Damit
# "jede verhinderte Doppelzustellung ist sichtbar" überhaupt stimmt, muss der
# Store zwei Eigenschaften haben:
#   * ATOMAR — `Repository::add()` MIT TTL delegiert an `Store::add()`; das ist
#     bei database/redis/memcached/dynamodb genau ein bedingtes Schreiben
#     (`insert … on conflict do nothing`, Lua-Skript, memcached `add`).
#   * GETEILT — FPM (`MandantMailerService::send`) und der Worker (`handle`)
#     sind zwei Prozesse. `array` ist prozesslokal, `file` pro Container,
#     `null` hat kein `add()` (der Claim stirbt an `BadMethodCallException`).
# Mit `CACHE_STORE=array` claimten zwei Worker denselben Auftrag beide, beide
# senden, und die zweite Zustellung hinterlässt weder eine `failed_jobs`-Zeile
# noch eine Logzeile: die stille Variante, die der Claim verhindern soll, bei
# gesund aussehendem Deployment. Fail-closed beim Start, wie Detail 1 und 2.
case "${CACHE_STORE:-}" in
	database | redis | memcached | dynamodb) ;;
	*)
		echo 'FATAL: CACHE_STORE muss ein GETEILTER Store sein (database, redis, memcached, dynamodb) — sonst ist der Idempotenz-Claim aus App\Jobs\SendMandantMail prozesslokal und verhindert keine Doppelzustellung.' >&2
		exit 1
		;;
esac

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
#
# --- `--tries`: BODEN für Jobs OHNE eigenen Deckel, nicht die Obergrenze ---
# Ein Job, der seinen Deckel selbst mitbringt (`$tries`/`backoff()`), trägt
# ihn im Payload — und Laravel bevorzugt ihn gegenüber dem CLI-Wert, weil
# `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` und
# `::markJobAsFailedIfWillExceedMaxAttempts()` beide mit
# `$maxTries = ! is_null($job->maxTries()) ? $job->maxTries() : $maxTries`
# beginnen (`Worker.php:703` und `:731`). Die CLI-Zahl hebt einen solchen
# Deckel also NICHT an.
#
# GEMESSEN (nicht vermutet): `App\Jobs\SendMandantMail` trägt `$tries = 5`. Auf
# der CLI mit `--tries=1` gestartet wurde der Job FREIGEGEBEN — die Zeile blieb
# in `jobs` liegen, `attempts = 1`, kein Eintrag in `failed_jobs`. Für diesen
# Job ist die CLI-Zahl also wirkungslos; wer hier 3 liest, bekommt 5. Deshalb
# steht hier 5: die Zahl, die ein Operator liest, ist die Zahl, die der
# einzige Job auf dieser Queue tatsächlich erhält.
#
# Wofür der Boden gebraucht wird: für alles, was seinen Deckel NICHT
# mitbringt — Laravels eigene `SendQueuedMailable` und
# `SendQueuedNotifications` tragen überhaupt kein `$tries`, ebenso jeder
# künftige Job ohne `$tries`. Heute ist `SendMandantMail` der einzige
# `ShouldQueue`-Typ im App-Code; `app/Mail/*` nutzt nur das `Queueable`-Trait
# und ist damit KEIN Job.
#
# Und dieser Boden ist fail-closed, nicht endlos: die CLI-Vorgabe von
# `queue:work` ist `[default: "1"]`, ein Job ganz ohne Deckel wird also nach dem
# ERSTEN Fehlversuch dead-lettered, nicht in einer Schleife gehalten. Genau
# deshalb wird `--tries` hier nicht weggelassen (das wäre Boden 1 statt 5),
# sondern auf den realen Job-Deckel gehoben.
queue_supervisor_loop() {
	while :; do
		php artisan queue:work \
			--name=accriditation-production \
			--tries=5 \
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
