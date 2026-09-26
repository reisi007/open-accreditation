# 05 — E2E-/Test-Image `accriditation-e2e` (CI)

**Status:** Implementiert 2026-08-19 (verifiziert via CI, `ci.yml` Job `e2e` grün).
Determinismus-/Provenienz-Aussagen korrigiert 2026-09-26 (WP-9-D1/D2) — siehe
„Determinismus & Provenienz“; der Digest-Pass-through ist lokal am Image verifiziert,
aber **noch nie in CI gelaufen** (der `Publish immutable reference`-Step in
`base-image.yml` hatte bis dahin keinen Lauf).

## Problem

Jeder E2E-CI-Run lud Playwright-Chromium + apt-System-Deps neu herunter
(`npx playwright install --with-deps chromium`) — obwohl die Browser-Version an
`@playwright/test` gebunden ist und sich zwischen Version-Bumps nicht ändert.

## Messung (2026-08-19, Step-Level-Zerlegung)

| Schritt | alt (Runner) | neu (Container) | Δ |
|---|---|---|---|
| Initialize containers (Image-Pull + Services) | 22s | 44s | **+22s** (1,7 GB Image-Pull aus GHCR) |
| Playwright-Browser-Install | 24s | 1s (No-Op) | **−23s** |
| E2E-Smoke-Tests (`@smoke`, serial) | 41s | 41s | ±0 |
| **Job gesamt** | **2m04s** | **2m11s** | **+7s ≈ ±0** |

**Fazit:** Kein Wall-Clock-Gewinn in diesem Repo — die E2E-Suite ist klein (nur
`@smoke`, serial, 41s) und `ubuntu-latest` bringt die meisten Playwright-System-Deps
bereits mit, daher war der Browser-Install dort schon günstig (24s). Der Image-Pull
(~22s) zehrt die Ersparnis exakt auf. **Der Gewinn liegt woanders:**
- **Prod-Runtime-Parität:** Das Backend läuft in `accriditation-base` — exakt dem
  Snapshot, aus dem das E2E-Image gebaut wurde (PHP-Extensions
  exiftool/ImageMagick/pdo_pgsql identisch) statt setup-php auf ubuntu-latest
  → keine Umgebungs-Drift zwischen Test und Produktion *innerhalb eines Laufs*.
  Über die Zeitachse ist die Parität so gut, wie der Base-Pin reicht (siehe
  „Determinismus & Provenienz“).
- **Stabile E2E-Umgebung:** Browser-Version == `@playwright/test` (Lockfile), kein
  Download aus flakigen CDNs/apt-Mirrors pro Run. Das war und ist die Determinismus-
  Aussage dieses Images — nicht mehr (siehe unten).
- **Netz-Workload der Runner:** ~200 MB weniger Download pro Run (Browser + apt-Deps),
  dafür +1,7 GB Image-Pull — per GHCR.

Bewusst beibehalten trotz ±0: Parität > Speed, und bei wachsender
E2E-Suite (volle Suite statt nur Smoke) skaliert der Container-Vorteil (Browser-Install
wäre dann konstant 24s+ pro Run, Image-Pull bleibt konstant ~22s).

## Determinismus & Provenienz (Stand 2026-09-26, WP-9-D1/D2)

Bis hierher behauptete dieses Dokument (SOLL-Zustand Punkt 2) eine Stärke, die der
Mechanismus nicht lieferte: der Tag `:<playwright-version>` sei „immutable,
Debug/Rollback“ — dieselbe Behauptung stand in `e2e-image.yml` als „immutable … for
reproducible, rollback-safe runs“. Falsch war das, weil der Wochen-Cron genau diesen
Tag bei gleicher Playwright-Version erneut baut und überschreibt. Und die Basis war
ohnehin unbepinnt, weil `Dockerfile.e2e` den **beweglichen** Tag
`accriditation-base:8.5` im `FROM` hatte — der E2E-Image-Build hing damit am selben
Moving-Target, gegen das die eigene Determinismus-Behauptung argumentierte. Was jetzt
gilt:

**Was garantiert wird**

- **Der Basis-Snapshot ist ein Digest, kein Tag.** `e2e-image.yml` löst vor dem
  Build den Digest auf, den `accriditation-base:8.5` gerade hat
  (`docker buildx imagetools inspect --format '{{.Manifest.Digest}}'`), und reicht
  ihn als `BASE_REF` an `deployment/Dockerfile.e2e`
  (`ARG BASE_REF` + `FROM --platform=${BASE_PLATFORM} ${BASE_REF}`).
- **Die Provenienz steht am Artefakt, nicht nur im Log:** der tatsächlich benutzte
  Wert wird als OCI-Label `org.opencontainers.image.base.name` ins Image
  geschrieben (plus `…base.platform`). Damit ist die Frage „welcher Base-Bitstand
  steckt in E2E-Image-Digest X?“ nach einem Pull mit `docker image inspect`
  eindeutig beantwortbar.
- **Der Digest ist derselbe wie der `image_ref`-Output von `base-image.yml`.** Ein
  Push setzt Tag und Digest; beide können per Konstruktion nicht auseinanderlaufen.
- **`PLAYWRIGHT_VERSION` und `PNPM_VERSION` sind gegen das Repo fixiert** (aus
  `pnpm-lock.yaml` bzw. `package.json#packageManager`), nicht geraten.

**Was ausdrücklich NICHT garantiert wird**

- **Keine Bit-Reproduzierbarkeit.** Drei Inputs driften unabhängig vom Repo-Stand:
  `composer:2` (beweglicher Major-Tag), das `latest-v26.x/`-Verzeichnis auf
  nodejs.org (wandert) und der Base-Digest selbst (rotiert nightly). Ein Rebuild
  desselben Commits liefert also **kein** bit-identisches Image. Der Pin macht den
  Lauf *auditierbar*, nicht *reproduzierbar*.
- **Kein automatischer Rebuild, wenn das Base-Image rotiert.** `e2e-image.yml`
  triggert nicht auf `base-image.yml`. Nach einem nightly Base-Rebuild läuft die
  E2E-CI bis zum nächsten wöchentlichen `e2e-image.yml`-Lauf auf dem älteren
  E2E-Image — der Test prüft dann gegen die alte, nicht gegen die neue Basis.
  Das ist eine bewusste Trade-off-Entscheidung (Wochencron als Obergrenze für die
  Basis-Drift), keine Implicit-Garantie.
- **Kein harter Fehler, wenn die Digest-Auflösung ausfällt.** Schlägt sie fehl
  (Registry nicht erreichbar, Paket nicht öffentlich, Buildx-Formatunsupported),
  baut `e2e-image.yml` gegen den beweglichen Tag weiter und annotiert das per
  `::warning::` plus Zeile im Job-Summary. Das E2E-Image bleibt lauffähig; es
  verliert nur die Base-Provenienz. Ein nicht auflösbarer Digest macht den Job
  **nicht** rot.
- **`FROM <repo>@sha256:<index-digest>` braucht ein explizites `--platform`.**
  Das Base-Image wird mit `platforms: linux/amd64` gepusht, der ghcr-Index führt
  also nur ein `linux/amd64`-Manifest. Ohne `--platform=linux/amd64` findet
  BuildKit auf einem arm64-Host kein passendes Manifest und bricht mit
  `no match for platform in manifest: not found` **hart** ab. Deshalb steht im
  Dockerfile `ARG BASE_PLATFORM=linux/amd64` (per `--platform` am `FROM` gesetzt
  und zusätzlich als LABEL `…base.platform` ausgewiesen) — der Fall wurde lokal auf
  darwin/arm64 reproduziert und behoben, nicht vermutet.

**Ein im Repo hartkodierter Digest wäre die schlechtere Lösung gewesen:** er
rotiert nightly und würde still altern, ohne dass ein Fehler sichtbar wird. Der
Digest muss CI-Laufzeit sein.

## SOLL-Zustand

1. **`deployment/Dockerfile.e2e`** — Derivat von `accriditation-base` (PHP-8.5-Prod-
   Runtime inkl. exiftool/ImageMagick/pdo_pgsql; Debian trixie), konkret über
   `ARG BASE_REF` + `FROM --platform=${BASE_PLATFORM} ${BASE_REF}`:
   - `BASE_REF` = vom Workflow aufgelöster **Digest** (Default ohne Build-Arg: der
     bewegliche Tag `accriditation-base:8.5`), `BASE_PLATFORM` = `linux/amd64`
   - `org.opencontainers.image.base.name` / `…base.platform` als LABEL → Provenienz
     am Artefakt
   - Composer (Dist-Binary via `COPY --from=composer:2`)
   - Node.js (aktuelles `v26`, offizielles Linux-Binary von nodejs.org)
   - pnpm (exakt `frontend/package.json#packageManager`)
   - Playwright-Chromium inkl. apt-Deps (`PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`,
     Version == `@playwright/test` via Build-Arg)
2. **`.github/workflows/e2e-image.yml`** — Rebuild-Trigger:
   - Push auf main: `deployment/Dockerfile.e2e`, Workflow selbst, `frontend/pnpm-lock.yaml`
     (Dependabot-Playwright-Bumps → Browser müssen im Image nachziehen)
   - Weekly (Mo 02:00 UTC) als Frische-Untergrenze (analog zur daily 01:00 UTC
     für `accriditation-base` in `base-image.yml`)
   - `workflow_dispatch` manuell
   - **Basis-Auflösung** als eigener Step vor dem Build (`Resolve base image
     reference`): Digest von `:8.5` per `docker buildx imagetools inspect`, als
     `BASE_REF` durchgereicht; fällt das aus, übernimmt der bewegliche Tag und
     der Job annotiert es per `::warning::` (nicht fatal)
   - Tags: `:latest` (beweglich, von CI referenziert),
     `:<playwright-version>` (**beweglich** — wird vom Wochen-Cron überschrieben),
     `:<playwright-version>-<run_number>` (immutable, wird nie überschrieben) und
     der Digest als Job-Output `image_ref` (immutable, im Job-Summary publiziert)
   - **Versionsextraktion aus dem Lockfile** (`frontend/pnpm-lock.yaml`), nicht aus
     `package.json`: dort steht ein Caret-Range (`^1.63.0`), das Lockfile resolvet
     die exakte Version (`1.63.0`) — Browser müssen exakt dazu passen.
3. **`ci.yml` Job `e2e`** läuft komplett im Container
   (`container: ghcr.io/reisi007/accriditation-e2e:latest`):
   - `setup-php` entfällt (PHP-Komplett-Runtime im Image mit exakt Prod-Extensions)
   - Backend direkt im Container: `composer install` → `key:generate` → `jwt:secret` →
     `migrate` → `seed` → `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`
   - Daten-Dienste per Service-Name (Container-Modus): `DB_HOST=postgres`,
     `DB_PORT=5432`, `MAIL_HOST=mailpit`, `MAIL_PORT=1025`
     → `.env`-Override im Prepare-Step; `.env.example` selbst bleibt unverändert
   - `MAILPIT_API_URL=http://mailpit:8025/api/v1` (Job-Env; `helpers/mailpit.ts` liest
     die Env-Var seit jeher, Default bleibt `localhost:8025` für lokale E2E)
   - `npx playwright install chromium` bleibt als **No-Op-Fallback** (Belt-and-Suspenders
     bei Versionsdrift zwischen Dependabot-Bump und Image-Rebuild; ohne `--with-deps`,
     da apt-Deps im Image gebacken sind)

## Rate-Limiter-State (P3e-B5)

Named Rate-Limiter (u. a. `login`) persistieren ihre Zähler im **DB-Cache-
Store** (`backend/config/cache.php`, Default `CACHE_STORE=database`) mit
beobachteter Persistenz bis zu **7 Tagen**. Lokale Back-to-Back-E2E-/
Screenshot-Läufe gegen den persistenten Dev-Postgres können dadurch
Login-Throttle-**429**s produzieren, obwohl jeder Lauf „frisch" startet.

**Vor jedem lokalen E2E-Lauf:** `php artisan cache:clear` (festgehalten als
Hinweis in `scripts/e2e-up.sh`). Der CI-E2E-Job ist nicht betroffen: er
migriert je Job eine frische Datenbank (leere Cache-Tabelle) — es gibt keinen
übertragenen Limiter-State zwischen Runs.

## Invarianten (nicht regredieren)

- **Nur die Umgebung einbacken** — nie App-Code, `node_modules/`, `vendor/`.
  Tests laufen gegen den aktuellen Commit; Dependencies werden pro Commit installiert.
- Browser-Version == `@playwright/test` (Lockfile!); der `frontend/pnpm-lock.yaml`-Trigger
  in `e2e-image.yml` erzwingt den Image-Rebuild bei Dependabot-Bumps.
- Container-Modus: Dienste per Service-Namen, keine `127.0.0.1`-Port-Mappings.
- **Kein Build-Arg im Repo fest verdrahtet, das rotiert.** Der Base-Digest ist
  CI-Laufzeit; eine Repo-Konstante würde still altern. Entsprechend darf kein
  „immutable/reproducible“-Versprechen ohne den Mechanismus aus
  „Determinismus & Provenienz“ in dieses Dokument wandern.

## Fallback (nur falls Container-Modus-Probleme auftreten)

`e2e`-Job auf Runner-Hosted zurückstellen (mit `shivammathur/setup-php`) und Browser
aus dem Image extrahieren statt per `playwright install`:

```bash
docker create --name pw-cache ghcr.io/reisi007/accriditation-e2e:latest
docker cp pw-cache:/ms-playwright "$HOME/ms-playwright"
docker rm pw-cache
echo "PLAYWRIGHT_BROWSERS_PATH=$HOME/ms-playwright" >> "$GITHUB_ENV"
```

## Quellen

- Portal-Referenz: `portal.reisinger.pictures/features/infrastructure/28-ci-test-image.md`
  (gleiches Muster, dort inkl. Speedup-Messung und Stripe-Idempotency-Fix-Doku).