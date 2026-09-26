#!/bin/sh
# ==========================================================================
# open-accreditation — Container-Einstieg (deployment/entrypoint.sh)
# --------------------------------------------------------------------------
# Wird per `CMD` aufgerufen (NICHT per `ENTRYPOINT` — der geerbte
# `docker-php-entrypoint` der `php:fpm`-Images bleibt intakt, siehe Kopf des
# Dockerfiles). Zwei Modi:
#
#   serve    (Default) Startet php-fpm. Aufgerufen vom `backend`-Service.
#   migrate  Einmaliger Deploy-Schritt. Aufgerufen vom `migrate`-Service:
#            migrate -> storage:link -> [db:seed] -> [config:cache|config:clear]
#
# Beide Modi laufen durch DENSELBEN MEDIA_ROOT-Guard weiter unten — genau
# deshalb wurde der Guard aus dem früheren Dockerfile-`CMD` hierher verschoben:
# der `migrate`-Service überschreibt das `CMD` und hätte den Guard sonst
# umgangen (eine Migration gegen ein kaputtes Deployment wäre dann genau der
# Moment, in dem man es am wenigsten bemerkt).
#
# POSIX `sh` (Debian → dash): keine Bash-Syntax, keine Arrays.
# ==========================================================================
set -eu

mode="${1:-serve}"

log() { printf '>> %s\n' "$*"; }
warn() { printf 'WARN: %s\n' "$*" >&2; }
fail() { printf 'FATAL: %s\n' "$*" >&2; }

# `true` / `1` / `yes` / `on` (case-insensitive) = aktiv. Bewusst eine
# Whitelist statt `[ "$x" = "true" ]`: ein exportiertes `RUN_SEEDER=True`
# darf nicht still als "aus" durchrutschen.
is_enabled() {
	case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
	true | 1 | yes | on) return 0 ;;
	*) return 1 ;;
	esac
}

# --------------------------------------------------------------------------
# MEDIA_ROOT-Guard (Exit 78 = EX_CONFIG) — für BEIDE Modi, Wortlaut unverändert
# gegenüber dem früheren Dockerfile-CMD (er wird in Kommentaren und Doku
# referenziert).
# --------------------------------------------------------------------------
if [ -n "${MEDIA_ROOT:-}" ] && { [ ! -d "$MEDIA_ROOT" ] || [ ! -w "$MEDIA_ROOT" ]; }; then
	echo "FATAL: MEDIA_ROOT='$MEDIA_ROOT' fehlt oder ist nicht schreibbar fuer uid=$(id -u) ($(id -un))." >&2
	echo "       Docker legt einen fehlenden Bind-Quellpfad als LEERES root-eigenes Verzeichnis an -> Uploads/PDFs schlagen dann still fehl." >&2
	echo "       Fix auf dem Host:  sudo mkdir -p '$MEDIA_ROOT' && sudo chown -R $(id -u):$(id -g) '$MEDIA_ROOT'" >&2
	exit 78
fi

# Der Deploy-Schritt. Jedes Kommando ist explizit `|| return 1`, damit `set -e`
# im Retry-Aufruf nichts verschluckt.
run_deploy_step() {
	log "migrate: php artisan migrate --force"
	php artisan migrate --force || return 1

	# `storage:link` ist idempotent. Das Image legt `public/storage` bereits
	# beim Build an (als root, weil `public/` im non-root-Container nicht
	# schreibbar ist) — der Aufruf meldet dann nur
	# `The [public/storage] link already exists.` und beendet sich mit 0.
	# Vorab geprueft, damit ein ERFOLGREICHER Deploy-Log nicht mit einer roten
	# ERROR-Zeile endet (sonst trainiert das Operatoren, Fehler zu ignorieren).
	# Fehlt der Link (abweichendes Image), legt der Aufruf ihn hier an.
	# Pfad = `working_dir` + `/public` (siehe `docker-compose.yml`).
	if [ -L /var/www/html/public/storage ] || [ -e /var/www/html/public/storage ]; then
		log "migrate: public/storage existiert bereits (aus dem Image) — storage:link entfaellt."
	else
		log "migrate: public/storage fehlt -> php artisan storage:link"
		php artisan storage:link || return 1
	fi

	if is_enabled "${RUN_SEEDER:-}"; then
		# ACHTUNG: `DatabaseSeeder` legt in `production` den Super-Admin an,
		# sobald ADMIN_EMAIL *und* ADMIN_PASSWORD gesetzt sind. Deshalb
		# default OFF und NIE implizit.
		log "migrate: RUN_SEEDER aktiv -> php artisan db:seed --force"
		php artisan db:seed --force || return 1
	else
		log "migrate: RUN_SEEDER nicht aktiv -> Seeding uebersprungen (Default)."
	fi

	if is_enabled "${RUN_CACHE_WARMUP:-}"; then
		# `config:cache` schreibt `bootstrap/cache/config.php` — landet im
		# geteilten Volume `app_cache` und ist damit auch fuer `backend`
		# sichtbar. NACHFOLGE: die Konfiguration ist ab hier ein Schnappschuss
		# der Deploy-Umgebung. Danach braucht jede ENV-Aenderung ein
		# Re-Deploy (der `migrate`-Service laeuft bei geaenderter Service-Config
		# neu und schreibt die Datei neu) oder ein manuelles
		# `php artisan config:clear`.
		log "migrate: RUN_CACHE_WARMUP aktiv -> php artisan config:cache"
		php artisan config:cache || return 1
	else
		# Default: KEIN Cache. `config:clear` laeuft bewusst auch im
		# Aus-Zweig — sonst bliebe eine einmal aktivierte, dann abgeschaltete
		# Warmup-Option als stale `config.php` im Volume liegen.
		log "migrate: RUN_CACHE_WARMUP nicht aktiv -> php artisan config:clear"
		php artisan config:clear || return 1
	fi

	return 0
}

case "$mode" in
serve)
	log "serve: php-fpm startet als uid=$(id -u)/$(id -un), MEDIA_ROOT='${MEDIA_ROOT:-<nicht gesetzt>}'"
	exec php-fpm
	;;
migrate)
	log "migrate: einmaliger Deploy-Schritt als uid=$(id -u)/$(id -un)"
	log "migrate: APP_ENV='${APP_ENV:-<nicht gesetzt>}' APP_DEBUG='${APP_DEBUG:-<nicht gesetzt>}'"
	log "migrate: Ziel-DB ${DB_HOST:-?}:${DB_PORT:-?}/${DB_DATABASE:-?} als ${DB_USERNAME:-?}"
	log "migrate: MEDIA_ROOT='${MEDIA_ROOT:-<nicht gesetzt>}'"

	# Gebundener Retry. `depends_on: db: condition: service_healthy` ist die
	# primaere Absicherung; der Retry deckt das schmale Fenster ab, in dem
	# `pg_isready` schon "ready" meldet, der Server aber (Restart, Recovery,
	# `ALTER SYSTEM`-Reload) noch keine Verbindungen annimmt. Die Obergrenze
	# ist hart: `DEPLOY_STEP_ATTEMPTS` Versuche, Default 3, plus
	# `DEPLOY_STEP_RETRY_SLEEP` (Default 5 s) dazwischen — der Schritt kann
	# also nicht endlos haengen, und der Abbruch bleibt sichtbar.
	max_attempts="${DEPLOY_STEP_ATTEMPTS:-3}"
	retry_sleep="${DEPLOY_STEP_RETRY_SLEEP:-5}"
	case "$max_attempts" in
	'' | *[!0-9]* | 0)
		max_attempts=3
		warn "DEPLOY_STEP_ATTEMPTS='${DEPLOY_STEP_ATTEMPTS:-}' ist keine positive Zahl — benutze 3."
		;;
	esac

	attempt=1
	while :; do
		if run_deploy_step; then
			log "migrate: Deploy-Schritt erfolgreich (Versuch ${attempt}/${max_attempts})."
			break
		fi
		if [ "$attempt" -ge "$max_attempts" ]; then
			# Backticks sind escaped: in "…" wuerden sie als Command-Substitution
			# ausgefuehrt (der Bug, der hier einmal `backend` zu starten versuchte).
			fail "Deploy-Schritt nach ${attempt} Versuch/Versuchen fehlgeschlagen — Abbruch. Der \`backend\`-Service startet dadurch gar nicht erst (depends_on: service_completed_successfully)."
			exit 1
		fi
		warn "Deploy-Schritt fehlgeschlagen (Versuch ${attempt}/${max_attempts}) — neuer Versuch in ${retry_sleep}s."
		attempt=$((attempt + 1))
		sleep "$retry_sleep"
	done

	log "migrate: fertig."
	exit 0
	;;
*)
	fail "unbekannter Modus '${mode}' (erwartet: serve|migrate)"
	exit 64
	;;
esac
