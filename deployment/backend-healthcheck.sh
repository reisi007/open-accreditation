#!/bin/sh
# ==========================================================================
# open-accreditation — Container-Healthcheck für den `backend`-Service
# --------------------------------------------------------------------------
# Der Healthcheck muss VIER Dinge als laufend verlangen (AGENTS.todo.md
# Position 45/46): php-fpm, den Queue-Supervisor, den Queue-Worker und den
# Scheduler. Fehlte einer davon, wäre ein stiller Zustellungsstillstand ein
# „gesundes" Deployment — genau der Zustand, den dieses Feature beendet.
#
# WARUM EIN EIGENES SKRIPT UND NICHT EIN INLINE-`CMD-SHELL`:
#   1. Compose interpoliert `$` in `CMD`/`CMD-SHELL`-Arrays; das Inline-
#      Äquivalent braucht `$$`-Escaping fuer jede Shell-Variable und wird
#      dadurch unlesbar und fehleranfällig.
#   2. Dieses Skript ist mit `sh -n` prüfbar und wird im Dockerfile beim Build
#      syntaktisch geprüft — das Inline-Äquivalent ist es nicht.
#
# WARUM `/proc` UND NICHT `kill -0`: dieses Repo hat am 2026-09-29 GEMESSEN,
# dass `kill(pid, 0)` auf einem ZOMBIE erfolgreich ist (Position 21: PID 1
# reapet im Container nie). Ein Zombie-„Supervisor" würde mit `kill -0` als
# gesund durchgehen, obwohl niemand mehr arbeitet. Deshalb wird der
# Prozess-STATUS aus `/proc/<pid>/stat` gelesen und `Z`/`X` als „nicht
# laufend" abgelehnt — fail-closed. Kein `ps`/`pkill` nötig (die fehlen auf
# dem Zielhost ohnehin).
#
# POSIX `sh` (dash): keine Bash-Syntax, keine Arrays.
# ==========================================================================
set -u

SUPERVISOR_PID_FILE=/tmp/accriditation-queue-supervisor.pid
WORKER_PID_FILE=/tmp/accriditation-queue-worker.pid
SCHEDULER_PID_FILE=/tmp/accriditation-scheduler.pid

unhealthy() {
	printf 'unhealthy: %s\n' "$*" >&2
}

# 1) PID 1 muss php-fpm sein. Der Supervisor endet mit `exec php-fpm -F`,
#    also ist php-fpm PID 1 — nur wenn der Supervisor nie lief oder php-fpm
#    gestorben ist, fehlt das. `/proc/1/cmdline` ist NUL-getrennt; `grep -a`
#    behandelt es als Text.
if ! grep -qa 'php-fpm' /proc/1/cmdline 2>/dev/null; then
	unhealthy 'PID 1 is not php-fpm (supervisor did not exec php-fpm, or php-fpm died)'
	exit 1
fi

# 2) Der FPM-Master muss auch wirklich FastCGI-Verbindungen annehmen. Der
#    Cmdline-Check oben belegt nur, dass der Prozess existiert — ein hängender
#    Master würde ihn bestehen. `php -r` statt `nc`, damit keine zusätzlichen
#    Pakete ins Image müssen (unverändert aus dem früheren Inline-Healthcheck).
if ! php -r "exit(@fsockopen('127.0.0.1', 9000) ? 0 : 1);" 2>/dev/null; then
	unhealthy 'php-fpm is not accepting FastCGI connections on 127.0.0.1:9000'
	exit 1
fi

# Ist $1 eine PID, die im /proc-Baum existiert UND nicht Z/X (Zombie/tot) ist?
is_live_pid() {
	_ilp_pid="$1"
	[ -n "$_ilp_pid" ] || return 1

	# Reine Ziffernfolge; alles andere ist eine kaputte PID-Datei.
	case "$_ilp_pid" in
	*[!0-9]*) return 1 ;;
	esac

	[ -r "/proc/${_ilp_pid}/stat" ] || return 1

	# Feld 3 von /proc/<pid>/stat ist der Prozess-Status. Feld 2 (comm) darf
	# Leerzeichen UND Klammern enthalten, deshalb wird zuerst alles bis zum
	# LETZTEN ')' entfernt — `cut -d' '` würde an einem ')' im comm
	# scheitern.
	_ilp_state=$(sed -e 's/.*) //' -e 's/ .*//' "/proc/${_ilp_pid}/stat" 2>/dev/null)

	case "$_ilp_state" in
	Z | X | '') return 1 ;;
	esac

	return 0
}

# PID-Datei vorhanden UND Prozess lebt UND ist kein Zombie?
check_pid_file() {
	_cpf_file="$1"
	_cpf_label="$2"

	if [ ! -s "$_cpf_file" ]; then
		unhealthy "${_cpf_label} pid file missing or empty (${_cpf_file})"
		return 1
	fi

	_cpf_pid=$(cat "$_cpf_file" 2>/dev/null)

	if ! is_live_pid "$_cpf_pid"; then
		unhealthy "${_cpf_label} (pid ${_cpf_pid:-?}) is not running"
		return 1
	fi

	return 0
}

status=0
check_pid_file "$SUPERVISOR_PID_FILE" 'queue supervisor' || status=1
check_pid_file "$WORKER_PID_FILE" 'queue worker' || status=1
check_pid_file "$SCHEDULER_PID_FILE" 'scheduler' || status=1

if [ "$status" -ne 0 ]; then
	exit 1
fi

echo 'healthy: php-fpm + queue supervisor + worker + scheduler running'
exit 0
