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
# Moment, in dem man es am wenigsten bemerkt). Dort steht auch der
# Mount-Check (W6), der ein nicht gemountetes Volume am Boot erkennt — der
# Fall, den „existiert und ist schreibbar" NICHT abdeckt.
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

# --------------------------------------------------------------------------
# MEDIA_ROOT-Mount-Guard (Exit 78 = EX_CONFIG) — für BEIDE Modi, W6.
# --------------------------------------------------------------------------
# Warum EXTRA zu "existiert + schreibbar" oben: beides ist auch dann
# erfüllt, wenn das Volume gar nicht gemountet ist — und ein nicht
# gemountetes Volume ist von einem LEEREN per Definition nicht
# unterscheidbar. Genau das ist die dokumentierte Restunsicherheit von
# `MediaStorage::delete()`: der `! exists($path)`-Vorcheck meldet einen Pfad
# auf einem nicht sichtbaren Volume als "nichts zu löschen", also als ERFOLG.
# Der W6-Fall sieht am laufenden System damit aus wie ein gesundes
# Deployment (jeder Upload antwortet 201), und die Dateien liegen in der
# writable layer des Containers: weg mit dem naechsten
# `docker compose up -d --build`, bzw. zurueck nach einem Remount, wo sie
# niemand mehr referenziert. Genau dieser blinde Fleck wird hier am Boot
# geschlossen.
#
# Das Signal ist der VERGLEICH MIT DER MOUNT-TABELLE, nicht "ist das
# Verzeichnis leer": ein GEMOUNTETES, leeres MEDIA_ROOT ist ein vollkommen
# legitimer Erst-Boot (frisches Deployment, noch kein Upload) und darf
# niemals abbrechen — genau deshalb wird die Leere hier nicht geprueft.
# Umgekehrt ist ein MEDIA_ROOT, das weder selbst noch ueber einen seiner
# Vorfahren ein Mount-Point ist, per Definition container-lokal; der
# komplette Media-Baum ueberlebt dann kein Redeploy. Das ist der Fall, der
# hier abbricht.
#
# `/` ist bewusst KEIN gueltiger Treffer: der Overlay-Root ist in jedem
# Container ein Mount-Point, sonst beantwortet die Pruefung konstant
# "abgedeckt" und waere blind.
#
# `MEDIA_ROOT_REQUIRE_MOUNT=false` (oder `0`/`no`/`off`) nimmt den Abbruch
# fuer den bewussten Wegwerf-Fall zurueck (Dev-`docker run` ohne `-v`, CI-
# Smoke-Container) — die Warnung bleibt. Ohne gesetztes `MEDIA_ROOT` greift
# der Guard nicht: das gebaute Image setzt keines, ein Container ganz ohne
# Volume ist damit per Default unauffaellig, und genau die ausgelieferte
# Konfiguration (`deployment/docker-compose.yml`, `${MEDIA_ROOT_HOST}:/srv/
# media/accreditation`) deckt den Abbruch ab.

# Mount-Point-Pruefung: steht $2 in Spalte $1 der Tabelle $3?
mount_table_has_point() {
	mt_field=$1
	mt_table=$2
	mt_point=$3

	# `while … done < datei` laeuft in der aktuellen Shell (anders als eine
	# Pipe) — nur so kann die Schleife den Treffer per `return` melden, ohne
	# eine Subshell zu brauchen.
	while IFS= read -r mt_line; do
		# `cut` verlangt exakt ein Leerzeichen als Trenner: /proc-Zeilen
		# sind es auch. Pfade mit Leerzeichen sind hier nicht unterstuetzt —
		# ein MEDIA_ROOT mit Blank kann den Guard nicht ausloesen (er
		# verhaelt sich dann wie "nicht feststellbar"), nie aber faelsch
		# abbrechen.
		mt_entry=$(printf '%s\n' "$mt_line" | cut -d ' ' -f "$mt_field")

		if [ "$mt_entry" = "$mt_point" ]; then
			return 0
		fi
	done < "$mt_table"

	return 1
}

# Der Mount-Point, der $1 abdeckt ( $1 selbst oder ein Vorfahre, ohne `/` ),
# auf stdout. Rueckgaben: 0 = abgedeckt (Mount-Point auf stdout), 1 = KEIN
# Mount (Tabelle lesbar, aber nichts deckt $1 ab), 2 = nicht feststellbar
# (keine Mount-Tabelle lesbar). Die Trennung 1 vs. 2 ist Pflicht: "nicht
# feststellbar" darf NIE abbrechen, "feststellbar und ohne Mount" ist der
# Befund, der abbrechen darf.
covering_mount_point() {
	cm_point=$1
	cm_table=''
	cm_field=0

	# /proc/self/mountinfo (Feld 5 = Mount-Point) zuerst; /proc/mounts und
	# /etc/mtab (Feld 2) als Fallback fuer Laufzeiten ohne mountinfo.
	for cm_candidate in /proc/self/mountinfo /proc/mounts /etc/mtab; do
		if [ -r "$cm_candidate" ]; then
			cm_table=$cm_candidate

			case "$cm_candidate" in
			/proc/self/mountinfo) cm_field=5 ;;
			*) cm_field=2 ;;
			esac

			break
		fi
	done

	if [ -z "$cm_table" ] || [ "$cm_field" -eq 0 ]; then
		# Kein Befund, nur ein Loch: der Aufrufer warnt und laesst den Start
		# durch. Lieber ein blinder Durchstart als ein Blindabbruch.
		return 2
	fi

	cm_dir=$cm_point

	# Trailing Slashes normalisieren: sonst vergleicht der erste Durchlauf
	# `/srv/media/accreditation/` mit dem Mount-Point `/srv/media/accreditation`
	# und der Weg ueber `dirname` SPRINGT ueber den echten Mount-Point hinweg —
	# ein korrektes Deployment wuerde dann faelschlich als container-lokal
	# gemeldet.
	while [ "$cm_dir" != "/" ] && [ "${cm_dir%/}" != "$cm_dir" ]; do
		cm_dir=${cm_dir%/}
	done

	while [ "$cm_dir" != "/" ] && [ -n "$cm_dir" ]; do
		if mount_table_has_point "$cm_field" "$cm_table" "$cm_dir"; then
			printf '%s\n' "$cm_dir"

			return 0
		fi

		cm_dir=$(dirname "$cm_dir")
	done

	return 1
}

if [ -n "${MEDIA_ROOT:-}" ]; then
	# Ohne absoluten Pfad ist gegen die Mount-Tabelle nichts vergleichbar
	# (Bind-Mount-Ziele sind immer absolut) — das ist ein "nicht feststellbar",
	# kein Fehlerbefund. Bewusst als `if` und nicht als `case`: ein `case` mit
	# einem `/*)`- UND einem `*)-` Arm ist syntaktisch gueltig, der erste
	# passende Arm gewinnt — und `*` haette den absoluten Zweig stillschweigend
	# verschluckt (genau dieser Fehler stand kurz in diesem Skript).
	if [ "${MEDIA_ROOT#/}" = "$MEDIA_ROOT" ]; then
		warn "MEDIA_ROOT='$MEDIA_ROOT' ist kein absoluter Pfad -> Mount-Check nicht anwendbar; der Media-Baum ist womöglich container-lokal."
	else
		media_cover=$(covering_mount_point "$MEDIA_ROOT") && media_state=0 || media_state=$?

		if [ "$media_state" -eq 0 ]; then
			if [ -z "$(ls -A "$MEDIA_ROOT" 2>/dev/null)" ]; then
				# Ausdruecklich KEIN Fehler: ein gemountetes, leeres
				# MEDIA_ROOT ist der normale Erst-Boot. Nur der Zustand wird
				# benannt, damit ein leerer Media-Baum im Log erklaert ist
				# statt vermutet zu werden.
				log "MEDIA_ROOT='$MEDIA_ROOT' ist gemountet (Mount-Point '$media_cover'), aber leer — legitimer Erst-Boot, kein Fehler."
			else
				log "MEDIA_ROOT='$MEDIA_ROOT' ist gemountet (Mount-Point '$media_cover'), $(ls -A "$MEDIA_ROOT" 2>/dev/null | wc -l) Eintraege."
			fi
		elif [ "$media_state" -eq 2 ]; then
			# Weder Befund noch Abbruch: die Mount-Tabelle ist nicht lesbar
			# (kein /proc, exotische Runtime). Der Start laeuft weiter, das
			# Fehlen der Pruefung wird aber sichtbar gemacht — auch mit
			# gesetztem MEDIA_ROOT_REQUIRE_MOUNT, denn das Umgekehrte waere
			# ein Abbruch OHNE Befund.
			warn "MEDIA_ROOT='$MEDIA_ROOT': keine Mount-Tabelle lesbar (/proc/self/mountinfo, /proc/mounts, /etc/mtab) -> der Mount-Check konnte nicht laufen. Start laeuft weiter."
		elif is_enabled "${MEDIA_ROOT_REQUIRE_MOUNT:-}"; then
			fail "MEDIA_ROOT='$MEDIA_ROOT' liegt in KEINEM Mount (weder selbst noch ueber einen Vorfahren, ohne '/'): der komplette Media-Baum waere container-lokal und waere mit dem naechsten 'docker compose up -d --build' weg. MEDIA_ROOT_REQUIRE_MOUNT ist gesetzt, also Abbruch (Exit 78)."
			echo "       Fix auf dem Host:  Volume-Mount in der Compose-Datei pruefen (${MEDIA_ROOT_HOST:-<MEDIA_ROOT_HOST>}:$MEDIA_ROOT) und den Host-Pfad anlegen:" >&2
			echo "                          sudo mkdir -p '${MEDIA_ROOT_HOST:-$MEDIA_ROOT}' && sudo chown -R $(id -u):$(id -g) '${MEDIA_ROOT_HOST:-$MEDIA_ROOT}'" >&2
			echo "       Absicht ohne Volume (Wegwerf-Container)?  MEDIA_ROOT_REQUIRE_MOUNT=false setzen." >&2
			exit 78
		else
			warn "MEDIA_ROOT='$MEDIA_ROOT' liegt in KEINEM Mount (weder selbst noch ueber einen Vorfahren, ohne '/'): Uploads/PDFs landen in der Container-writable layer und sind beim naechsten Redeploy weg — bzw. kommen nach einem Remount zurueck, auf die nichts mehr zeigt (das 'unmounted vs. empty'-Problem aus MediaStorage::delete())."
			echo "       Fix auf dem Host:  Volume-Mount in der Compose-Datei pruefen (${MEDIA_ROOT_HOST:-<MEDIA_ROOT_HOST>}:$MEDIA_ROOT) und den Host-Pfad anlegen:" >&2
			echo "                          sudo mkdir -p '${MEDIA_ROOT_HOST:-$MEDIA_ROOT}' && sudo chown -R $(id -u):$(id -g) '${MEDIA_ROOT_HOST:-$MEDIA_ROOT}'" >&2
			echo "       Hart abbrechen statt warnen:  MEDIA_ROOT_REQUIRE_MOUNT=true" >&2
		fi
	fi
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
