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
  `pnpm-lock.yaml` bzw. Workflow-Major-Linie `version: 11`, nicht geraten; seit
  Position 50 (2026-10-05) kein `packageManager`-Pin mehr).

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
   - pnpm (Major-Linie `version: 11` im CI-Workflow; kein `packageManager`-Pin
     mehr seit Position 50, 2026-10-05)
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

## Die zwei E2E-Laufprofile (Stand 2026-09-26, WP-9-D3)

Der E2E-Gate kennt **zwei** Playwright-Profile. Sie teilen `testDir`, `projects` und
`baseURL` (erst damit ist es garantiert **dasselbe** Testset) und unterscheiden sich nur im
Fehler-Budget:

| Profil | Config | Fehler-Budget (CI) | Gate |
|---|---|---|---|
| **Smoke** | `frontend/playwright.config.ts` | `retries: 2`, `maxFailures: 10` | `push` / `pull_request` / `workflow_dispatch` — `--grep @smoke --workers=1` (kritischer Pfad, schnell) |
| **Nightly** | `frontend/playwright.regression.config.ts` | `retries: 0`, `maxFailures: 1` | `schedule`-Cron — **volle** Suite, serial |

Die Event-Auswahl hängt allein an `github.event_name` (`schedule` ⇒ volle Suite, sonst
`@smoke`). Stand 2026-09-26 ist `pull_request` im `if:` des E2E-Jobs **tatsächlich**
enthalten — vorher stand dort nur `push`/`schedule`/`workflow_dispatch`, d. h. ein PR
bekam **nie** einen E2E-Check, obwohl AGENTS.md §7 den Push-/PR-Gate so beschreibt.
Voraussetzung dafür ist ein **public** GHCR-Package für
`ghcr.io/reisi007/accriditation-e2e` (der Job zieht das First-Party-Image; ein
Fork-PR hat nur ein read-only `GITHUB_TOKEN`).

Der `concurrency`-Block trennt den Nightly vom Event-Lauf: `github.ref` ist beim
`schedule`-Event derselbe Default-Branch wie bei einem `push` darauf, und ohne
eigenen Group-Namen würde ein Push den Nightly mitten im Lauf abbrechen.
`cancel-in-progress: ${{ github.event_name != 'schedule' }}` ⇒ der Nightly wird nie
abgebrochen, alle anderen Runs schon.

**Warum das Nightly strikt bleiben MUSS:** Es ist der einzige Lauf, dessen Aufgabe das
*Erkennen* von Flakiness ist. Mit `retries: 2` gilt ein Test, der im ersten Versuch scheitert
und im Retry grün wird, als bestanden — der Job bleibt grün und die Flakiness ist unsichtbar.
`retries: 0` + `maxFailures: 1` bedeuten: **ein roter Test ist der komplette Befund** (kein
Weiterlaufen, um neun weitere Fehler zu beweisen). Deshalb wird das Nightly **nicht**
„grünkonfiguriert": ein roter Nightly wird behoben oder mit Datei/Testname + Ursache in
`AGENTS.todo.md` begründet — sichtbar dokumentiert, nicht wegretried. Das Smoke-Profil
bleibt demgegenüber bewusst verzeihend, weil geteilte GitHub-Runner echtes Timing-Rauschen
erzeugen; **beide** Werte nicht vermischen. `retries: 0` im Nightly-Profil ist dabei
**unbedingt** (CI *und* lokal — eine lokale Reproduktion muss denselben
Erstversuch-Befund zeigen, den das CI-Gate sieht); nur `maxFailures` ist `CI`-gated
(`1` in CI, `0` lokal = unlimited, damit ein lokaler Volllauf den vollständigen
Bericht zeigt).

Beide Profile lesen `use.baseURL` aus `E2E_BASE_URL` (Default `http://localhost:5173`) — und
dieselbe Env-Var liest auch der API-Helper-Layer
(`tests/e2e/helpers/admin-data.ts`, exportiert als `FRONTEND_BASE_URL`), sonst liefe der
Browser gegen den einen Stack, während die Fixtures gegen einen anderen gesetzt würden. Beide
Vite-Server pinnen Port 5173 mit `strictPort` (`server` **und** `preview`), damit keiner
der beiden still auf 5174 bzw. 4173 ausweicht. Der `vite preview`-Default-Port 4173 darf
die Suite also nicht still treffen.

## E2E-DB-Isolation: geteilte Zustands-Reserven (Stand 2026-09-26, WP-9-D4)

Der E2E-Gate fährt **alle** Specs gegen **eine** Datenbank — in CI eine frisch migrierte
(leerer Cache-Store, also kein übertragener Rate-Limiter-Zustand), lokal eine persistente
Dev-DB über mehrere Läufe hinweg. Daraus folgt der Isolations-Vertrag der Suite, der
**nicht** regressieren darf:

- **Fixtures sind markiert, Cleanup ist markiert — nie mandantweit.** Jedes E2E-Fixture
  trägt seinen Marker im Namen (`Portal-Test <workerKey> <ts>`, `E2E Akkreditierung <ts>`,
  `E2E Heimverein <ts>`, `E2E *`-Mandanten …), und ein Helper löscht **nur seinen eigenen**
  Marker. Mandantweit löschen darf ausschließlich die **serielle** `globalTeardown`
  (`purgeAllE2EArtifacts`), weil dort garantiert kein Test mehr läuft. Grund: `fullyParallel`
  lässt Desktop- und Mobile-Projekt **dieselbe** Spec gleichzeitig laufen; ein mandantweites
  Cleanup löschte dem jeweils anderen Projekt das lebende Fixture (gemessen: 7 von 24 Slots
  rot, reproduzierbar).
- **Worker-Key statt Run-Key.** Der Portal-Fixture-Key ist `w<TEST_WORKER_INDEX>-p<pid>`
  (Fallback `p<pid>`), weil ein Worker zu jedem Zeitpunkt genau *einen* Test ausführt —
  „darf ich das löschen?" ist damit ohne Koordination beantwortbar. Ein Run hat keine ID im
  Worker-Prozess, und beide Projekte teilen sich denselben Run. Das PID-Suffix ist
  zusätzlich nötig, weil `TEST_WORKER_INDEX` in jedem Host-Prozess wieder bei 0 beginnt:
  zwei **gleichzeitig** laufende `playwright test`-Läufe auf derselben Maschine hätten
  sonst beide `w0` und der eine löschte dem anderen das lebende Fixture.
- **Geteilter Zustand wird serialisiert, nicht wegdefiniert.** Das Logo des primären
  Mandanten schreibt `admin-mandant.spec.ts` (Upload) und liest `portal.spec.ts`
  (Fallback `/logo.svg`). Beide nehmen denselben `acquirePrimaryMandantLogoLock()`
  (exklusiv erzeugte Lock-Datei mit **Owner-Token** im Inhalt; Timeout **wirft** nach
  60 s; Stale-Lock wird nach **30 s** übernommen — das Stale-Fenster MUSS kleiner sein
  als der Timeout, sonst kann ein Wartender nie lange genug warten). `release()` prüft den
  Token vor dem `unlink`, damit ein Bestand, dessen Lock übernommen wurde, nicht den Lock
  seines Nachfolgers löscht. Keine Assertion wurde abgeschwächt.
- **Der Logo-Zustand wird auch wiederhergestellt, nicht nur gesperrt.** Sperren allein
  genügt nicht: stürzt ein Lauf zwischen Upload und Entfernen ab, behält der primäre
  Mandant das Logo und alle folgenden Läufe (inkl. der Screenshot-Captures) erben einen
  gefüllten Zustand. `resetPrimaryMandantLogo()` stellt den Seed-Zustand im seriellen
  `globalTeardown` (`purgeAllE2EArtifacts`) wieder her; die Screenshot-Suite hat **kein**
  eigenes `globalTeardown` und stellt ihn deshalb selbst her — `seedMandantLogoFree` in
  `tests/screenshots/helpers/seeds.ts`, an jede Route gehängt, die das Logo rendert.

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

## Reaper als PID 1 — und der daraus folgende bekannte Rote (Nutzerentscheid D26/D27, 2026-09-29)

**Entscheidung („no process leaks please"):** ein verwaistes Kind muss **wirklich
verschwinden**, nicht bloß nicht-ausführbar sein. Strikte Lesart, kein Toleranz-Ausbau.

**Gebaut:** `options: --init` am **`e2e`-Jobcontainer** in `ci.yml` — der Docker-Daemon-eigene
`docker-init` (tini) wird PID 1. **Bewusst kein `ENTRYPOINT` im Image:** bei einem Jobcontainer
bestimmt der *Runner* PID 1, nicht das Image; ein `ENTRYPOINT`, den der Runner ersetzt, wäre eine
Zeile, die nichts über das laufende System aussagt.

**Zwei Hälften, und die Unterscheidung trägt die ganze Aussage:**

| Hälfte | Status | Beleg |
|---|---|---|
| **Ausgang** — ein Reaper lässt das Waisenkind verschwinden | **outcome-proven** | `docker create --init` → `PID 1 comm=docker-init`, Orphan **GONE**; ohne → `state=Z`, `ppid=1` (Docker 29.8.1) |
| **Mechanismus** — GitHub wertet `options:` für einen Jobcontainer aus | **nur deklariert** | Workflow-Syntax-Referenz (nur `--network`/`--entrypoint` ausgeschlossen); im Runner-Quellcode **nicht** nachlesbar |

**Das ist der Punkt, den man zitieren muss:** der Verhaltenstest ist grün, sobald **irgendein**
Reaper existiert. Er beweist den **Ausgang**, nicht die **Ursache**. Nur die Deklarations-Pin im
Vitest verknüpft beides.

### Bekanntes Rot — akzeptiertes Risiko

`child-lifetime.spec.ts` **ist auf jedem Host ohne PID-1-Reaper dauerhaft rot.** Gemessen in einem
Entwickler-Container: `PID 1 = opencode`, Waisenkind `state=Z`, `ppid=1`.

**Warum das richtig ist:** §3 verlangt einen roten Zustand statt einer grünen Lüge. Ein Lauf, der
geleckt hat und grün ist, ist eine Lüge — dasselbe gilt für Prozesse. Die Alternative wäre, `Z`
wieder als „gut genug" zu akzeptieren, und genau das hat D26 abgeschafft.

**Was es kostet, offen benannt:**

- Der lokale E2E-Lauf ist **nicht mehr grün**, wenn man in einem Container ohne `init` arbeitet.
  Das ist der Preis, nicht ein Randfehler.
- Der **CI-Push-Gate** ruht vollständig auf der **unbewiesenen** Hälfte. Wäre `--init` dort
  inert, geht der `e2e`-Job bei jedem Push rot — in die richtige Richtung, aber ein selbst
  zugefügtes Rot über den **`e2e`-Job** (nicht über alle vier Gates), das hier dokumentiert ist.
- Auf **darwin** ist derselbe Test aus einem **anderen** Grund rot: kein procfs. Beide Gründe sind
  in der Fehlermeldung unterscheidbar.

**Auflösung der zweiten Hälfte:** nur ein echter CI-Lauf. Fällt er grün aus, ist (b) belegt; fällt
er rot, ist es laut statt still — was genau der Zweck ist.

> **Warum dieser Abschnitt hier steht und nicht nur im Board.** §3 verlangt, dass ein
> wissentlich roter Testbereich als akzeptiertes Risiko in `features/` festgehalten wird. Der
> erste Entwurf lag nur in `AGENTS.todo.md` — und §4 schneidet genau diese Datei weg. Ein §4-Durchgang
> hätte die Notiz still gelöscht. Das ist derselbe Fehler, den `AGENTS.md` §10 **A7** beschreibt:
> eine offene Position, die nirgends geführt war und deshalb verschwand.

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