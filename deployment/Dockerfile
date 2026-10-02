# syntax=docker/dockerfile:1
# ==========================================================================
# open-accreditation — Backend-Image (PHP-FPM 8.5)
# --------------------------------------------------------------------------
# DIESE DATEI HAT ZWEI ROLLEN (bewusst zusammengelegt, solange es kein
# eigenes `Dockerfile.prod` gibt):
#
#   1) BASIS-IMAGE `accriditation-base:8.5`
#      .github/workflows/base-image.yml baut MIT `context: deployment` und
#      OHNE Build-Args  ->  reiner Runtime-Layer, KEIN App-Code.
#   2) PROD-APP-IMAGE
#      deployment/docker-compose.yml baut MIT `context: ..` und
#      `--build-arg APP_SOURCE=1`  ->  App-Code + `vendor/` eingebackt,
#      non-root, selbst-contained (KEIN Bind-Mount des Host-Checkouts).
#
# WARUM EIN SCHALTER UND NICHT EINFACH `COPY backend/ …`:
# Ein `COPY` lässt sich in einem Dockerfile nicht konditionalisieren. Der
# Basis-Build hat `deployment/` als Kontext (kein `backend/` darin) — ein
# `COPY backend/ …` würde den CI-Build des Basis-Images rot machen. Der
# Kontext wird deshalb read-only GEMOUNTET (`RUN --mount=type=bind`) und der
# App-Code nur bei `APP_SOURCE=1` kopiert. Zwei Dichtungen: (a) ein
# Bind-Mount wird nie in einen Layer committet, (b) die `--exclude`-Liste
# unten filtert zusätzlich alles, was nicht in ein Prod-Image gehört
# (`.env`, `vendor/`, `storage/**`, `bootstrap/cache/*.php`, `tests/`).
#
# ANFORDERUNG AN DEN BUILD-CONTEXT (Prod-Rolle): Kontext ist der REPO-ROOT.
# Im Root liegt eine `.dockerignore` (Stand WP-5-D1), die genau das ausfiltert,
# was der Build nicht liest: `frontend/node_modules` (256 MB), `backend/vendor`
# (103 MB), `.git`, `.codegraph`, `backend/storage`, Test-/Build-Artefakte und
# `.env*`. GEMESSEN (Docker 29.5.3 / BuildKit 0.30, linux/arm64) sinkt der
# wirklich an den Builder gesendete Kontext dadurch von 359,7 MB auf 4,1 MB
# (materialisierter Kontextinhalt: 441 MB / 31.407 Dateien -> 5,2 MB / 519).
# Wichtig: BuildKit liest fuer einen Kontext NUR
# `<dockerfile>.dockerignore` bzw. `<kontext>/.dockerignore` — die Datei im
# Repo-Root gilt also ausschliesslich fuer `context: ..` und NICHT fuer die
# Basis-Image-Rolle mit `context: deployment`.
#
# `deployment/entrypoint.sh` wird von BEIDEN Rollen gebraucht (siehe unten) und
# muss deshalb im Repo committed sein — die Basis-Image-Rolle baut mit
# `context: deployment`, kann die Datei also nur finden, wenn sie dort liegt.
# Die `.dockerignore` fasst `deployment/` deshalb nicht an; sie dokumentiert am
# Ende jeder Regel, was sie NICHT ausschliesst. Wird das hier je angefasst,
# ist ein beider-Rollen-Build der Pflicht-Schritt.
#
# RUNTIME-USER: www-data (UID 33 / GID 33) — der FPM-Master läuft NICHT als
# root (R-D10). Der Container kann deshalb zur Laufzeit KEINE Rechte
# reparieren: `storage/` + `bootstrap/cache/` sind beim Build www-data-
# Owned, der Media-Bind-Mount muss auf dem Host UID 33 gehören.
#
# `Dockerfile.e2e` erbt dieses Image und setzt wieder auf root zurück
# (CI-Tool-Image, siehe dort).
# ==========================================================================

# PHP Version
ARG PHP_VERSION=8.5
# Gepinnte Composer-Version statt floatendem `composer:2`-Tag (CC-R4).
# Muss im globalen Scope stehen, damit `FROM` sie auflösen kann — `COPY
# --from` akzeptiert keine Variablen, daher die eigene Stage.
ARG COMPOSER_VERSION=2.10.3

FROM composer:${COMPOSER_VERSION} AS composer_src

FROM php:${PHP_VERSION}-fpm

# bash + pipefail für die RUNs mit Pipes: `tar | tar` darf nicht still
# teilweise kopieren, wenn die Quelle beim Lesen stirbt (ohne pipefail
# gewinnt der Exit-Code der empfangenden Seite). Debian hat /bin/bash.
SHELL ["/bin/bash", "-o", "pipefail", "-c"]

# Database driver: pgsql (default) or mysql
ARG DB_EXT=pgsql
# 0 = Runtime-only (Basis-Image-Rolle), 1 = App-Image mit Code + vendor/
ARG APP_SOURCE=0
# Unterverzeichnis des App-Quellcodes im Build-Kontext (siehe Rolle 2)
ARG APP_PATH=backend

# System dependencies & PHP extensions (GD, ExifTool, ImageMagick CLI, Zip, Intl, DB driver)
RUN apt-get update && apt-get install -y \
    libicu-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    libimage-exiftool-perl \
    imagemagick \
    --no-install-recommends \
    && if [ "${DB_EXT}" = "pgsql" ]; then apt-get install -y --no-install-recommends libpq-dev; fi \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) intl gd zip exif pdo_${DB_EXT} pcntl bcmath \
    && rm -r /var/lib/apt/lists/*

WORKDIR /var/www/html

# Composer — statisches Dist-Binary des offiziellen `composer:<version>`-Images
# (verifiziert vorhanden, kein floatender Tag). `unzip`/`git` braucht der
# Dist-Install nicht: ext-zip ist oben gebaut, `composer.json` setzt
# `preferred-install: dist`.
COPY --from=composer_src /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/composer \
    COMPOSER_CACHE_DIR=/composer/cache

# --------------------------------------------------------------------------
# App-Code + `vendor/` (nur Rolle 2, `APP_SOURCE=1`)
# --------------------------------------------------------------------------
# Ausgeschlossen wird bewusst:
#   .env*                     Secrets des Dev-Checkouts (nie im Prod-Image!)
#   vendor/                   wird frisch aus composer.lock installiert
#   storage/                  komplett frisches Skelett (siehe unten) —
#                             enthält im Dev-Checkout User-Portraits,
#                             Badge-Bilder und Test-Fixtures
#   bootstrap/cache/          dito (services.php kann DB-Creds cachen)
#   database/*.sqlite         lokale Dev-DB
#   tests/                    ohne require-dev ohnehin unbenutzbar
# Das Laravel-Skelett (`bootstrap/cache`, `storage/**`) wird hier VOR dem
# `composer install` angelegt: `post-autoload-dump` ruft `artisan
# package:discover`, das sonst an "bootstrap/cache must be present and
# writable" scheitert. Rechte folgen erst im nächsten Schritt.
# Das `APP_KEY=…` gilt NUR für dieses eine `composer install`: dessen
# `post-autoload-dump`-Script bootet die App, und der Boot-Guard
# (AppServiceProvider::assertProductionAppKeyIsStrong) verweigert in
# `production` — dem Laravel-Default ohne `.env` — den Start ohne Key. Der
# Wert ist ein frisch generierter Wegwerf-Key (im Image-Config weder als ENV
# noch im Layer gesetzt, `docker history` zeigt nur das Kommando); der
# echte Schlüssel kommt ausschließlich aus der Deployment-Umgebung.
RUN --mount=type=bind,ro,target=/ctx \
    mkdir -p /var/www/html/bootstrap/cache \
             /var/www/html/storage/app/media \
             /var/www/html/storage/app/private \
             /var/www/html/storage/app/public \
             /var/www/html/storage/framework/cache/data \
             /var/www/html/storage/framework/sessions \
             /var/www/html/storage/framework/views \
             /var/www/html/storage/logs \
    && if [ "${APP_SOURCE}" != "1" ]; then \
        echo ">> APP_SOURCE=${APP_SOURCE} -> Runtime-only image, no app code"; \
        exit 0; \
    fi; \
    tar -C "/ctx/${APP_PATH}" -cf - \
        --exclude='./.env' --exclude='./.env.*' \
        --exclude='./vendor' \
        --exclude='./storage' \
        --exclude='./bootstrap/cache' \
        --exclude='./tests' \
        --exclude='./.phpunit.result.cache' \
        --exclude='./database/*.sqlite' \
        . | tar -C /var/www/html -xf - \
    && APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" \
       composer install --no-dev --optimize-autoloader --no-interaction --no-progress \
    && rm -rf "${COMPOSER_CACHE_DIR}" \
    && composer --version

# --------------------------------------------------------------------------
# Datei-Rechte: Code root-owned und für den Runtime-User nur lesbar (der
# FPM-Prozess darf seinen eigenen Code nicht überschreiben). Schreibbar
# sind ausschließlich `storage/` und `bootstrap/cache/` — dort schreibt
# Laravel (Sessions, Cache, Views, Logs, kompilierte Config/Pakete).
# --------------------------------------------------------------------------
RUN chown -R root:root /var/www/html \
    && chmod -R a+rX /var/www/html \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# PHP Config (higher limits for PDF/image-intensive apps)
RUN echo "memory_limit = 1024M" > /usr/local/etc/php/conf.d/php-base.ini \
    && echo "upload_max_filesize = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "post_max_size = 64M" >> /usr/local/etc/php/conf.d/php-base.ini \
    && echo "max_execution_time = 600" >> /usr/local/etc/php/conf.d/php-base.ini

# --------------------------------------------------------------------------
# `public/storage`-Symlink — jetzt beim BUILD, nicht (nur) als Deploy-Schritt
# --------------------------------------------------------------------------
# `storage:link` MUSS als root laufen: `public/` ist oben bewusst root-owned
# und fuer `www-data` nur lesbar (siehe Non-root-Abschnitt) — der non-root
# Container kann den Symlink also gar nicht selbst anlegen. Er wird deshalb
# hier erzeugt. Der Deploy-Schritt in `deployment/entrypoint.sh` ruft
# `storage:link` NUR auf, wenn der Link fehlt (Vorab-Pruefung) — sonst
# wuerde jeder erfolgreiche Deploy-Log mit Laravels roter
# `The [public/storage] link already exists.`-Zeile enden.
# `public/` selbst wird NICHT schreibbar gemacht — die Code-Invariante aus dem
# Non-root-Abschnitt bleibt vollstaendig erhalten.
# Guard noetig: in Rolle 1 (`APP_SOURCE=0`) gibt es kein `public/`, und `ln`
# wuerde dort mit "No such file or directory" abbrechen und damit den
# CI-Base-Image-Build zerlegen.
RUN if [ -d /var/www/html/public ]; then \
        ln -sfn /var/www/html/storage/app/public /var/www/html/public/storage; \
        ls -l /var/www/html/public/storage; \
    else \
        echo ">> no app code in this image role — skipping the public/storage symlink"; \
    fi

# --------------------------------------------------------------------------
# Einstiegspunkt: deployment/entrypoint.sh (Modi `serve` | `migrate`)
# --------------------------------------------------------------------------
# Installiert wird über den read-only Kontext-Mount statt `COPY`, weil dieses
# Dockerfile zwei Build-Kontexte bedienen muss (siehe Kopf): Rolle 1 baut mit
# `context: deployment` (CI base-image.yml), Rolle 2 mit `context: ..`
# (Prod). Ein `COPY` lässt sich nicht konditionalisieren, also werden beide
# Layouts explizit geprüft und ein fehlendes Skript bricht den Build ab,
# statt still ein Image ohne Einstiegspunkt zu erzeugen.
#
# Der Smoke-Test im selben RUN beweist, dass das Skript ausführbar und
# POSIX-`sh`-tauglich ist (die `php:fpm`-Images nutzen dash als `/bin/sh`):
# ein unbekannter Modus muss mit 64 (EX_USAGE) abbrechen. Er startet weder
# `php-fpm` noch `php artisan`.
RUN --mount=type=bind,ro,target=/ctx \
    if [ -f /ctx/entrypoint.sh ]; then \
        src=/ctx/entrypoint.sh; \
    elif [ -f /ctx/deployment/entrypoint.sh ]; then \
        src=/ctx/deployment/entrypoint.sh; \
    else \
        echo "FATAL: entrypoint.sh not found in the build context (expected /ctx/entrypoint.sh or /ctx/deployment/entrypoint.sh)" >&2; \
        exit 1; \
    fi; \
    install -m 0755 "$src" /usr/local/bin/entrypoint.sh \
    && status=0; /usr/local/bin/entrypoint.sh bogus-mode >/dev/null 2>&1 || status=$?; \
    if [ "$status" -ne 64 ]; then \
        echo "FATAL: entrypoint.sh exited with ${status} instead of 64 for the unknown mode 'bogus-mode'" >&2; \
        exit 1; \
    fi

# --------------------------------------------------------------------------
# Betrieb: Queue-Supervisor + Healthcheck (Position 46)
# --------------------------------------------------------------------------
# `deployment/entrypoint.sh` (Modus `serve`) startet den Supervisor, und der
# Compose-Healthcheck des `backend`-Services ruft den Healthcheck auf. Beide
# Skripte werden deshalb — genau wie der Entry-Point — über den read-only
# Kontext-Mount installiert (KEIN `COPY`): dieses Dockerfile bedient zwei
# Build-Kontexte (Rolle 1 `context: deployment`, Rolle 2 `context: ..`). Ein
# `COPY` lässt sich nicht konditionalisieren, die beiden Layouts werden daher
# explizit geprüft. Fehlt ein Skript, bricht der Build ab, statt still ein
# Image ohne Worker/Scheduler zu erzeugen — genau die Lücke, die dieses
# Feature schliesst.
#
# Der `sh -n`-Smoke-Test prüft die POSIX-`sh`-Tauglichkeit (die `php:fpm`-
# Images nutzen dash als `/bin/sh`). Er startet weder `queue:work` noch
# `schedule:run` und keine `php-fpm`.
RUN --mount=type=bind,ro,target=/ctx \
    for supervisor_src in backend-supervisor.sh backend-healthcheck.sh; do \
        if [ -f "/ctx/${supervisor_src}" ]; then \
            src="/ctx/${supervisor_src}"; \
        elif [ -f "/ctx/deployment/${supervisor_src}" ]; then \
            src="/ctx/deployment/${supervisor_src}"; \
        else \
            echo "FATAL: ${supervisor_src} not found in the build context (expected /ctx/${supervisor_src} or /ctx/deployment/${supervisor_src})" >&2; \
            exit 1; \
        fi; \
        sh -n "$src" || { echo "FATAL: ${supervisor_src} ist kein gültiges POSIX-sh" >&2; exit 1; }; \
        case "$supervisor_src" in \
            backend-supervisor.sh) install -m 0755 "$src" /usr/local/bin/accriditation-backend-supervisor ;; \
            backend-healthcheck.sh) install -m 0755 "$src" /usr/local/bin/accriditation-backend-healthcheck ;; \
        esac \
    done

# --------------------------------------------------------------------------
# Non-root (R-D10). `php:fpm` startet ohne USER als root; www.conf's
# `user = www-data` ist dann nur für die Worker zuständig, der MASTER bleibt
# root. Mit `USER www-data` läuft auch der Master als www-data: die
# `user`/`group`-Direktiven werden dann ignoriert (zwei NOTICE-Zeilen im
# Log, die Worker laufen ohnehin als www-data) — verifiziert gegen
# php:8.5-fpm: Master lauscht als www-data auf 9000, Logs gehen nach stdout.
# --------------------------------------------------------------------------
USER www-data

# CMD statt ENTRYPOINT: der geerbte `docker-php-entrypoint` bleibt intakt,
# `docker run … php artisan …` und `docker exec` verhalten sich unverändert.
# Beide Rollen laufen durch dasselbe Skript — `backend` ruft es als `serve`
# auf, der einmalige `migrate`-Service als `migrate`. Nur so kann der
# MEDIA_ROOT-Guard nicht umgangen werden. Er hat ZWEI unabhängige Auslöser,
# beide mit Exit 78 = EX_CONFIG:
#   1. MEDIA_ROOT fehlt oder ist nicht schreibbar — Docker legt einen fehlenden
#      Bind-Quellpfad als LEERES root-eigenes Verzeichnis an, Upload/PDF/
#      Wallet-Pässe scheitern dann still. Das ist IMMER ein Abbruch.
#   2. MEDIA_ROOT liegt in KEINEM Mount (weder selbst noch über einen
#      Vorfahren) — der Fall "gemountet, aber nie benutzt" ist von "gar nicht
#      gemountet" am laufenden System nicht unterscheidbar, und der komplette
#      Media-Baum wäre container-lokal, also mit dem nächsten `up -d --build`
#      weg bzw. nach einem Remount ohne Referenz zurück.
#      Dieser Auslöser ist per Default NUR EINE WARNUNG und wird erst mit
#      `MEDIA_ROOT_REQUIRE_MOUNT=true` zum Abbruch; ohne gesetztes MEDIA_ROOT
#      greift er gar nicht.
# Fail-fast ist besser als 500er im Betrieb, und eine Migration gegen ein
# kaputtes Deployment wäre genau der Moment, in dem man es am wenigsten
# bemerkt — deshalb läuft der `migrate`-One-shot durch denselben Guard.
# Für ein Debug-Shell: `docker run --entrypoint sh …`.
CMD ["/usr/local/bin/entrypoint.sh", "serve"]
