# Task Board — open-accreditation

> Stand: 2026-08-15. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
>
> **Status (2026-08-15):** P1–P6 + UI-Polish (UI-Review-Befunde, Formular-Abstände) + P7-Hardening
> (F2–F5, B2/B3, P2a-RL, P4-F1, P5-F2, P1a-B1/B2/B4, P0-Fix-F3, P3d-F2, P2c-F3, P2b-F8, P4-F4) sowie
> P8/P8b (UI-Review-Skill + Mandant-Bilder Self-Service) sind **umgesetzt und verifiziert**: Backend
> 672 PHPUnit grün (APPROVED), Frontend 124 Vitest grün (APPROVED), E2E smoke + Features grün,
> Screenshot-Suite 57/57, finale Vision-Analyse 0 Issues. Commits: 8b340fa, 4b8497a, 9ec0ec2, c618ad0,
> 157ebfe, b78e2b8, a9d02ee, 2e6185f, acc2609. **Verbleibend:** P7 Go-Live (Caddy multi-Domain,
> Prod-Deploy — wartet auf Benutzer-Freigabe) + die unten gelisteten offenen Follow-ups + finaler
> User-Test.
>
> Test-Regel (DoD): Backend → PHPUnit (SQLite `:memory:`), Allocation-Logik → eigene PHPUnit-Tests,
> Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E (getaggt).

---

## 🎯 Entscheidungen (interaktiv geklärt 2026-08-13)

| # | Thema | Entscheidung |
|---|---|---|
| D1 | Stack | Laravel 13 + PHP 8.5 · React 19 + Vite + TS strict + Tailwind v4 + daisyUI v5 · neueste Deps aus Portal |
| D2 | DB | Postgres (Dev/Prod, docker-compose) + SQLite `:memory:` (Tests) |
| D3 | Multi-Tenant | „Mandant" = Brand-Muster aus Portal (Host-Header → MandantContext); eigene Domain pro Mandant; Hauptseite ist selbst ein Mandant; kein Theme, nur Logo/Header-Bilder + Legal-Texte |
| D4 | Hierarchie | Mandant = Verband · Team = Verein (optional, pro Mandant freischaltbar) · Kategorien erben vom Mandant, Team überschreibt |
| D5 | Anmelde-Scopes | Event/Spiel · Liga-weit · Saison (Verein) · Pro-Spiel |
| D6 | Account | Pro Mandant eigenes Konto (eigene Domain) |
| D7 | Rollen | super_admin, mandant_admin, team_admin, user, verifier (Ordner); Team-Admin sieht Verbands-Akkreditierungen eigener Personen read-only |
| D8 | Freigabe | Manuell + Automatismen (nach Fristende FCFS, nicht-gesperrt); immer Limit; Massenfreigabe (alle / erste X); Blacklist (Person + Domäne); VIP-Prio (Person/Domäne) |
| D9 | Sub-Akkreditierungen | Park-/Sitzkarte nur bei Haupt-Akkreditierung; eigenes Kontingent + auto/manuell; Überzeichnung → Ablehnung; VIP vorgereiht |
| D10 | Event | Titel, Datum, Ort (Default = Heimstätte Team, überschreibbar), Wettbewerb, Frist (Default, überschreibbar); Events auf Mandant- oder Team-Ebene (mit Teams nur Team-Ebene) |
| D11 | Ausweis | Feld-Editor (Pentaho-artig, „Luxus wenn möglich") + PDF-Export + CSV/Excel für Serienbrief |
| D12 | QR | Scan → öffentliche Prüfseite (Foto/Status); Ordner webbasiert online |
| D13 | Wallets | Apple + Google Wallet (PKPASS) |
| D14 | E-Mail | Voller Workflow (Aktivierung, Freigabe/Ablehnung, Frist-Reminder, Pass-Versand); SMTP je Mandant |
| D15 | Sprachen | DE + EN (Lingui) |
| D16 | Fotos | Porträt + Presse-ID + Anhänge (validiert) |
| D17 | Migrationen | Mit **Erstelldatum** nummerieren (Laravel-Format); bis zum 1. Prod-Deploy erweitern/frei anpassen, danach jede Änderung eigene Migration — in `backend/AGENTS.md` dokumentiert |
| D18 | Deployment/Proxy | **Caddy NUR remote** (Plan offen): serviert Frontend + `/api`-Proxy zum Backend auf einer Domain (React-Proxy-Muster wie Portal), Mandanten-Routing über Host-Header. **Lokal:** Herd-Backend `https://accreditation.test` + Vite-Dev-Server (Frontend, eigener Port, Proxy auf Backend) — **kein Caddy lokal**. |

---

## 📋 Phasen & offene TODOs

### P7 — Polish + Deploy 🟡 **AUF HALT — Go-Live wartet auf Benutzer-Freigabe**
> **Einziger verbleibender Block:** Alle Umsetzungsphasen P1–P6 + UI-Polish + P7-Hardening sind
> abgeschlossen (verifiziert, APPROVED). P7 wird erst nach expliziter Freigabe des Benutzers umgesetzt.
> Operativer Plan: siehe §🚀 Go-Live-Plan (unten). Caddy-SOLL: `features/03-caddy-brand-files.md`.

- [ ] Go-Live gemäß §🚀 Go-Live-Plan (Pre-Prod → Prod → Long-running)

---

## 🚀 Go-Live-Plan (2026-08-25)

> Zielbild: identisches/similar Infrastruktur-Muster wie `portal.reisinger.pictures`
> (globale `~/dev/caddyfile/Caddyfile`, Snippets `security_headers`/`compress`/`spa`,
> FastCGI `/api*` → Backend), erweitert um **per-Mandant austauschbare Brand-Ressourcen**
> (Logo/Favicon/Webmanifest pro Subdomain) via `brand_overrides`-Snippet
> (SOLL: `features/03-caddy-brand-files.md`). Verzeichnis-Keying: `/srv/websites/accreditation.<slug>`.

### Phase A — Pre-Prod-Deploy

**Infrastruktur**
- [ ] Pre-Prod-Subdomain festlegen (z. B. `preprod.accreditation.reisinger.pictures`) + DNS
- [ ] Site-Block im globalen `~/dev/caddyfile/Caddyfile` nach Portal-Muster (`security_headers`, `compress`, `/api*` fastcgi → `accreditation_backend:9000`, `spa`, `brand_overrides`)
- [ ] Server-Voraussetzungen: `/srv/websites/accreditation.<slug>`-Verzeichnisse (Frontend-Dist), Docker-Netzwerk für Caddy ↔ Backend, Volumes für DB + Media (private Disk)
- [ ] `caddy validate` vor Reload (Docker), Deploy-Mechanik wie Referenz (`sync.sh`)

**Umgebung & Config**
- [ ] `.env.preprod`: `APP_KEY`, `JWT_SECRET`, DB-Creds (Postgres-Container), Mail (Mailpit), `APP_ENV=staging`, `APP_URL` + Mandant-Domain-Hosts
- [ ] Frontend-Build für Pre-Prod (Vite `dist`) + Deploy-Pfad

**Verifikation vor Prod (Gates)**
- [ ] **USER-DECISION BE-R1**: globale vs. Per-Mandant `email`-Unique (`AuthController.php:39`) — MUSS vor erstem echten User-Data entschieden sein
- [ ] **Postgres-Portabilitäts-Gate:** Integration-/E2E-Lauf gegen echte Postgres (nicht nur SQLite-Testsuite), §2-Regel verifiziert
- [ ] **Multi-Domain-UX-Gate (P2c-F4):** Admin-Zugriff auf Nicht-Primär-Domain prüfen (Teams-Anzeige super_admin)
- [ ] **Brand-Override-Gate:** `brand_overrides` live testen — Mandant A mit eigenem Logo, Mandant B auf React-Fallback; Austausch (Upload Self-Service `POST /api/mandant/logo` → Datei im Dist-Ordner ersetzen/ergänzen) ohne Reload nachvollziehen
- [ ] Full E2E `@regression` grün gegen Pre-Prod (inkl. `@smoke`, Badge-PDF, QR-Verify, PKPASS)

### Phase B — Prod-Deploy

- [ ] Backup/Rollback-Basis: DB-Dump + alte Dist-Ordner vor jedem Deploy
- [ ] Prod-DNS für alle initialen Mandant-Domains + TLS (Caddy ACME automatisch)
- [ ] Site-Blöcke pro Mandant-Domain im globalen Caddyfile (Muster aus Phase A) — `caddy validate` + Reload
- [ ] `.env.production` auf Server (Secrets NUR serverseitig): `APP_KEY`, `JWT_SECRET`, DB, SMTP je Mandant (`smtp_config` JSON), SameSite=None-Cookie (BE-R6 bereits implementiert)
- [ ] `docker compose -f deployment/docker-compose.yml up -d` (Backend + Postgres), `php artisan migrate --force`, Storage-Link, `config:cache route:cache`
- [ ] Frontend-Dist je Mandant deployen (Fallback-Dist + optionale Overrides)
- [ ] Smoke gegen Prod: `@smoke`-E2E + manueller Check Login/Guest/QR/PDF/Wallet
- [ ] Monitoring/Basics: Log-Zugriff, Mail-Zustellung, Rate-Limiter-Verhalten in Prod

### Phase C — Long-running / Post-Go-Live

> Abgeschlossene Punkte entfernt: P3e-B3 (LikeSearch), P3e-B5 (e2e-up.sh), P3b-F2 (domain-model.md), P2b-F5 (domain-model.md), P1c (Profile-E2E), P3e-B4 (indexAll Endpoint), BE-R8 (domain-model.md), Vite-Proxy (Middleware), P2c-F4 (useAdminTeams `2e35df1`), P4-F4 (QR z-order `431ec99`).

---

## 🛠️ Session 2026-08-19 — CI-E2E-Test-Image `accriditation-e2e` (Portal-Behandlung)

> SOLL-Zustand: `features/05-e2e-test-image.md`. Gleiches Muster wie im Portal
> (`portal.reisinger.pictures`, `features/infrastructure/28-ci-test-image.md`): Test-Image mit
> vorinstallierten Playwright-Browsern → E2E-Job läuft komplett im Container.

- [x] `deployment/Dockerfile.e2e` (FROM `accriditation-base:8.5` + Composer/Node v26/pnpm/Playwright-Chromium)
- [x] `.github/workflows/e2e-image.yml` (Trigger: Dockerfile.e2e + Workflow + `frontend/pnpm-lock.yaml` + weekly + dispatch; Lockfile-basierte Playwright-Versionsextraktion — package.json trägt `^1.61.1`, Lockfile resolvet `1.62.1`)
- [x] `ci.yml` Job `e2e`: `container:` + `MAILPIT_API_URL` + `.env`-Overrides (postgres/mailpit per Service-Name) + setup-php entfernt + Playwright-Fallback-No-Op
- [x] Doku `features/05-e2e-test-image.md` + `features/README.md`-Index
- [x] **Commit 1** (Image + Doku) → Image-Build grün (`efdb27a`)
- [x] **Commit 2** (ci.yml) → CI komplett grün (`e2c1...`/Effektiv-Commits efdb27a + push ci.yml); E2E-Job läuft nachweislich im Container (Backend ohne setup-php, Browser-Check 1s)
- [x] **Speedup-Messung (ehrlich):** Job-E2E alt 2m04s → neu 2m11s (**±0**, +7s). Step-Zerlegung: Browser-Install −23s (24s→1s) wird vom Image-Pull +22s (Init-Containers 22s→44s) aufgezehrt. **Kein Wall-Clock-Gewinn in diesem Repo**, da E2E-Suite klein (nur @smoke, serial, 41s) und ubuntu-latest die meisten PW-Deps eh mitbringt. **Gewinn = Determinismus + Prod-Runtime-Parität** (Backend in exakt `accriditation-base:8.5` statt setup-php auf ubuntu) — bewusst behalten, Zahlen in `features/05`.

---

## 🔍 Open Follow-ups (verifiziert, aber offen)

> Abgeschlossene Punkte entfernt: P3e-B5, P3b-F2, P2b-F5, P3e-B3, P1c, RV-U3, P5-F3, P6-B2, FE-R3, P3e-B4 (bereits umgesetzt), Vite-Proxy (Middleware), BE-R8 (Doku), P2c-F4 (useAdminTeams `2e35df1`), P4-F4 (QR z-order `431ec99`).

- [ ] **P2c-F4 (info)** super_admin nähert „aktuellen Mandant" als Primär-Mandant an (Dev ok; Nicht-Primär-Domain zeigt falsche Teams) → Multi-Domain-Admin-UX in P3/P7.

---

---

## 🛠️ Session 2026-08-19b — Follow-up-Fixes (delegiert, wartet auf Verifikation)

> SOLL: risikoarme Follow-ups aus §Offene Follow-ups abarbeiten (User hat keine Zeit für Go-Live).
> Kontext: Tippfehler `open-accriditation`→`open-accreditation` bereits bereinigt (siehe unten/`git status`).
> opencode-DB + aktuelle Session zeigten bereits den korrekten Pfad → kein Eingriff nötig.

- [x] **Tippfehler-Repo-Bereinigung** — Source/Config-Strings (`package.json`, `.env`/`.env.example`, `README.md`, `features/README.md`, `scripts/e2e-up.sh`, `AGENTS.md`/`.todo.md`) + `node_modules` via Clean-Reinstall (0 alte Pfade) + stale Blade-Views gecleared. opencode-DB/Session unverändert (schon korrekt). GitHub-Remote `reisi007/open-accreditation` bestätigt (existiert, korrekt benannt, Work gepusht).
- [x] **E2E-Test-Hygiene (low)** — erledigt + verifiziert (APPROVED). `badge.spec.ts` löscht `E2E Ausweis*`-Template via `afterAll`; `ensurePrimaryMandantActivePortalEvent` self-cleaning; `purgeAllE2EArtifacts` + `globalTeardown` (nur E2E-präfixierte Artefakte, `Hauptseite` nie betroffen). `@feature:badge` E2E grün, Mandanten 36→5, DB sauber; `pnpm build`/`lint:fix` grün.
- [x] **P4-F3 eigener Limiter (low)** — erledigt + verifiziert (APPROVED). Dedizierter `verify`-Limiter in `AppServiceProvider` (60/min prod, 300/min test, per-IP), `routes/api.php:311` auf `throttle:verify`; `portal`/`accreditations` bleiben `throttle:public`. 17 neue Throttle-Tests, Voll-Suite 678 grün.
- [x] **P4-F2 Write-on-Read (low)** — erledigt + verifiziert (APPROVED). `QrTokenService::token()` (rein, kein DB-Write) im `AdminApplicationResource`; `make()` persistiert weiterhin (Approval/Resend/Backfill). Neuer idempotenter Command `accreditation:backfill-qr-tokens`. 3 neue Tests (inkl. Regressions-Test: Serialisierung persistiert NICHT), Voll-Suite 678 grün.

---

## 🔧 Workflow (delegieren + verifizieren)- Build-Agent: nur diese Datei + `AGENTS.md` (+ referenzierte Doku). Kein Produktiv-Code.
- Jeder TODO-Block → ein Implementer-Subagent (isoliert, präzise Anweisungen + Ziel-Dateien).
- Jede Umsetzung → ein **separater** Verifikator-Subagent (Tests/Lint/Build, Diff-Review **+ Architektur- und Security-Review** nach `AGENTS.md` §5; `critical`/`high` blockieren APPROVED).
- Visuelle Checks (Template-Editor, Ausweis-Layout, Screenshot-Abgleiche) → `vision`-Subagent.

## 📌 Offene Punkte / Risiken

- [x] Repo-Tippfehler `open-accriditation` → `open-accreditation` bereinigt.
- [x] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel §2 durchgesetzt (keine PG-spezifischen Features in Migrationen/Queries; SQLite-Testsuite läuft).
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4) — **User-Input nötig**
- [ ] Google-Wallet: API-Zugang/Issuer-Setup erforderlich (externer Schritt, P6)

---

## 🔍 Whole-Repo Code Review — 2026-08-20 (STATUS)

> **Methode:** 3 Review-Subagenten (Backend / Frontend / Cross-Cutting) + Backend-Tests (678 grün) +
> Frontend (lint/build/124 vitest grün). **User-Regel:** „akzeptiert/info" ≠ akzeptiert → separat re-assessed.
> Fixes delegiert (parallel, unabhängig von Severity). Build-Agent orchestriert nur (AGENTS.md §5).
> **Hinweis:** Diese Sektion wurde durch den Tree-Churn (Branch-Switches/Resets der Parallel-Agenten)
> einmal verworfen → daher direkt auf `master` geschrieben (nicht auf einem Fix-Branch).

### Gesundheit
- Backend `php artisan test`: **683 grün (4407 assertions)** — konsolidiert auf `master` (678 Baseline + BE-R3 2 + BE-R2 3 neue Tests). BE-R2-Refactor sauber abgeschlossen (`Event.php` Import behoben).
- Frontend `lint:fix`/`build`/124 vitest: **grün** — FE-R1 (Pluralisierung) erledigt + committet.

### Findings (neu) — Status
**Backend**
- [ ] **BE-R1 · MEDIUM · USER-DECISION** — `AuthController::register` globale `email`-Unique vs Per-Mandant (`AuthController.php:39`). Entscheidung ausstehend.
- [x] **BE-R2 · MEDIUM · DONE (committed on master)** — Tenant-Isolation-Safety-Net via **Route-Model-Binding-Resolver** + `MandantContext::hasCurrent()` (`Support/MandantContext.php`, `Models/*`, neue `TenantIsolationBindingTest`). Global Scope bewusst nicht (24 legitime Tests mit Cross-Mandant-Rows).
- [x] **BE-R3 · LOW · DONE (committed on master)** — `updateRoles` Mandant-Mitgliedschafts-Guard + 2 Tests.
- [x] **BE-R4 · LOW · DONE (committed on master)** — UserController-Suche `escapeLike()` (CC-R1-Controller im selben Commit gebündelt).
- [x] **BE-R5 · LOW · DONE (committed on master)** — apply-Rate-Limiter `user('api')` (AppServiceProvider.php:71).
- [x] **BE-R6 · LOW · DONE (committed on master)** — JWT-Cookie `SameSite=None` in prod / `Lax` in dev (Controller.php).
- [x] **BE-R7 · LOW · DONE (committed on master)** — negativer Host-Cache bei Domain-Anlage geleert (`MandantContext::forgetHost` in `MandantDomainController::store`).
- [ ] **BE-R8 · INFO · DOKUMENTIEREN** — VIP/denied nicht durch Bulk-Run reanimierbar (design limitation).

**Frontend**
- [x] **FE-R1 · MEDIUM · DONE (committed on master ed73305)** — Pluralisierung `accreditationLabels.ts:31,48` → ICU + DE/EN-Kataloge (124 vitest grün).
- [x] **FE-R2 · LOW · DONE (committed on master)** — `DeadlineCountdown.tsx` + `UsersPage.tsx` ICU-Plural + DE/EN-Kataloge.
- [ ] **FE-R3 · INFO · OK** — `VerifyPage.tsx:71` img-src, kein JS-Risiko.

**Cross-Cutting / Infra**
- [x] **CC-R1 · MEDIUM · DONE — korrigiert 2026-08-20** — LIKE → `LOWER()` in `AdminApplicationController:85-86`, `PortalController:81`, `BlacklistController:51`. **Früherer „DONE"-Eintrag war falsch**: kein Review-Commit hatte die 3 Controller berührt (diff `09b2949..HEAD` leer); der §5-Verifikator (F11) verwechselte die prä-existierenden `LOWER()`-Exists-Checks (BlacklistController:90/96) mit der Suche. Jetzt real umgesetzt + je Testdatei case-mismatch-Assertions (`search=SPAM`/`ALICE`/`JANE`, `competition=OKAL` — Postgres-LIKE ist case-sensitiv, SQLite nicht).
- [x] **CC-R2 · MEDIUM · DONE (committed on master)** — DB-Credentials → env/secret (`docker-compose.yml`, `ci.yml`). Owner: `E2E_POSTGRES_PASSWORD`-Secret konfigurieren.
- [x] **CC-R3 · LOW · DONE (committed on master)** — DB-Port auf `127.0.0.1:5432:5432` (localhost-only).
- [x] **CC-R4 · LOW/INFO · DONE (committed on master)** — Digest/SHA-Pin-TODOs zu `:latest`-Images + GH-Actions (CI/docker-compose).
- [x] **CC-R5 · LOW/INFO · DONE (committed on master)** — `.env.example` `APP_DEBUG=false` (Prod-Footgun entfernt).

### Re-Assessment der „akzeptiert/info"-Follow-ups (Subagent, read-only)
- **FIX (3) — ERLEDIGT:** `P3a-F1` (→ FE-R2, committet), `P3c-F4` (Allocation-**Test**-Lücken ergänzt: VIP+Blacklist-Precedence, case-insensitive, approveAll idempotent, exact-fit quota), `P4-F5` (`features/`-SOLL-Docs Badge/QR/PDF + Wallet/PKPASS ergänzt).
- **USER-DECISION (1):** `P6-B1` (`relevantDate` Event-Datum vs `deadline_end`, WalletPassService.php:549,562).
- **LEAVE (15), davon STALE/zu schließen (4):** `F7`, `P3e-B5`, `B3`, `P3a-F2` (bereits implementiert/mitigiert).
  Rest defensibel: `P3e-B3`, `P3e-B4`, `P3b-F2`, `P2b-F5`, `P2c-F4`, `P1c`, `P4-F4`, `P5-F3`, `P5-F4`, `P6-B2`, `Vite-Proxy`.

### Branch-Status (git) — KONSOLIDIERT
- Alle Fixes **auf `master`** committet (8 Commits ahead of origin: 7 Fixes + 1 docs). Scratch-`fix/*`-Branches
  + Stashes aufgeräumt. Keine offenen Feature-Branches mehr.

### Verification
- Voll-Suite auf `master` grün: **Backend 689 passed (4451 assertions)**, **Frontend 124 vitest + build + lint**.
- Formaler **separater Verifikator (AGENTS.md §5)** als Batch über die Review-Commits (`09b2949..HEAD`)
  ausgeführt → **Verdict APPROVED** (Architektur + Security, keine critical/high-Befunde, kein Regressions-Risiko).
  **Korrektur aus dem Lauf:** F11 („CC-R1 schon im Base vorhanden") war ein Fehlleser — CC-R1 wurde danach
  real implementiert (siehe Finding-Liste) und die Suite erneut grün gefahren.

### Offene Entscheidungen / Owner-Action
- **P6-B1** RESOLVED (dokumentiert): `relevantDate` = `deadline_end` (Event-Datum als Fallback) — Entscheidung in `WalletPassService.php` + `features/wallet-pkpass.md`.
- **CC-R2**: Owner-declined — E2E DB braucht **kein** sicheres Passwort (explizite Owner-Entscheidung); auf plain `accriditation` vereinfacht, keine Secret-Config nötig.

### Status — ALLE Review-Findings erledigt
- Code-Fixes (FE-R2, BE-R6, BE-R7, P3c-F4, CC-R1) + Infra/Docs (CC-R3, CC-R4, CC-R5, P4-F5, P6-B1) committet.
- **Voll-Suite grün:** Backend **689 passed (4451 assertions)**, Frontend **124 vitest** + `lint` + `build`.
- **§5-Verifikator abgeschlossen: APPROVED** (Batch über `09b2949..HEAD`, Architektur + Security, keine
  critical/high-Befunde). Einziges Korrektiv aus dem Lauf: CC-R1 war faktisch nicht umgesetzt → nachgeliefert
  + Regressionstests ergänzt.
- CC-R2-Owner-Action entfällt (E2E DB kein sicheres Passwort nötig, plain `accriditation`).
- `AGENTS.todo.md` bereinigt; Befunde ggf. → `features/`/`Security Risk Register`.
- **Gepusht** an `origin/master` (alle lokalen Commits).

### CI-Folge-Befund (2026-08-20): FE-R2-Regression im E2E-Smoke — GEFIXT
- **Symptom:** CI-E2E-Smoke rot in 3 Runs (`portal.spec.ts:57` erwartet `/Noch \d+ Tage/`).
- **Ursache (Root-Cause via Playwright-Snapshot `text: Noch Tage E2E Heimverein …`):**
  FE-R2-ICU-Messages in `DeadlineCountdown.tsx` hatten **kein `#`** in den Plural-Zweigen
  (`one {Tag}` statt `one {# Tag}`) → Countdown rendert „Noch Tage" **ohne Zahl**.
  FE-R1 war korrekt (`{# Platz frei}`); `check-i18n`/vitest sind blind für fehlendes `#`
  (prüfen nur PO↔JS-Sync, nicht ICU-Inhalt) → Lücke, die nur E2E/Live-Auge zeigt.
- **Fix:** `#` in beide Messages (`# Tag`/`# Tage`, `# Stunde`/`# Stunden`),
  `lingui:extract` + EN-msgstr nachgezogen + `lingui:compile`.
- **Regressionstest:** `portal.spec.ts:57` (war 3× rot, nach Fix grün — CI verifiziert).
  Zusätzlich geprüft: keine weitere Plural-Message ohne `#` im Source.
- **Nebenwirkung:** GC von obsoleten Katalogeinträgen bewusst NICHT durchgeführt
  (`extract --clean`), da dies der bestehende Repo-Standard ist (alte Keys bleiben im
  kompilierten JS als Restbestand — unschädlich).

## 📦 Dependency-Update — 2026-08-23

Durchgeführt (Branch `chore/deps-2026-08-23`, via PR gemergt):
- **Frontend (pnpm):** `packageManager` pnpm@11.21.0 → pnpm@11.23.0; MAJOR `@testing-library/jest-dom` 6.9.1→7.0.1 und `jsdom` 29.1.1→30.0.1; Minor/Patch (daisyui, eslint, vite, vitest, @vitejs/plugin-react, @hookform/resolvers, react-hook-form, dompurify, @iconify-json/material-symbols, @testing-library/user-event, @vitest/coverage-v8).
- **Backend (composer):** `php` ^8.4 → ^8.5; MAJOR `phpunit/phpunit` 11→13.3.1; `laravel/framework` 13.26.1 + Minor/Patch.

Verzögert / blockiert (nicht Teil dieses PRs):
- **typescript 6→7:** Repo bereits auf TS 6 (^6.0.3). 7.x nur migrieren, sobald Framework/Peer-Tooling es unterstützt — aktuell zu frisch.
- `guzzlehttp/guzzle` 7→8: blockiert durch direkten Dep `http-interop/http-factory-guzzle` (nur psr7 ^1.7||^2.0, keine 3.0-fähige Version).
- `brick/math` 0.18→0.19: gedeckelt durch `ramsey/uuid` (<=0.18).

---

## 🛠️ Session 2026-08-25 — Follow-up-Batch (Orchestrator, ohne Go-Live + User-Abnahme)

> User-Auftrag: Alle offenen TODOs umsetzen, die **keinen** User-Input brauchen (P7 Go-Live +
> finale Abnahme + offene Entscheidungen BE-R1/Feld-Editor/Google-Wallet ausgenommen). Umsetzung
> als Orchestrator → delegiert an Implementer, separat verifiziert (§5). Konsolidierung auf `master`,
> keine `fix/*`-Branches. Max. 2 Subagenten parallel bei disjunkten Ziel-Dateien.

### TODO-Liste (actionable, mit Test-Forderung)
### Low Follow-ups (info, aus Verifikation)
> Abgeschlossene Punkte entfernt: P1c-F1 (email-Kommentar), P1c-F2 (robuste Assertion), P3e-B4-F2 (SWR-Key dokumentiert), P3e-B4-F3 (grouped orderBy), P3e-B4-F1 (Concern-Extraktion `d372693`), P2-F2 (akzeptiertes Risiko), P2-F1 (Non-ASCII dokumentiert `d372693`), P2b-F5 (is_team_override dokumentiert `d372693`), E2E-Hygiene (badge_images purge).

### Bewusst NICHT in diesem Batch (braucht User / externe / Go-Live-Infra)
- P7 Go-Live (User-Freigabe) · finale User-Abnahme · BE-R1 (E-Mail-Unique-Scope, User-Entscheidung)
- Feld-Editor-Umfang (P4, User-Klärung) · Google-Wallet-Issuer (extern)
- P5-F4 Queue-Integration (braucht Queue-Worker → Go-Live-Infra, Post-MVP belassen) · P5-F3/P6-B2 (bereits dokumentierte MVP-Entscheidungen)

---

## 🛠️ Session 2026-08-25b — User-Entscheidungen (interaktiv geklärt) + Umsetzung

> Entscheidungen vom Benutzer: **BE-R1 = Per-Mandant `email`-unique** · **Google-Wallet jetzt einleiten** ·
> **Feld-Editor = voll frei positionierbar** · **Go-Live weiterhin geparkt**.

### TODO-Liste (actionable, mit Test-Forderung)
_(Alle Tasks dieser Session umgesetzt + verifiziert — inkl. Feld-Editor FE1–FE4: `a17332b`, `eb88cbc`, `8634e40`, `68f52d7`; badge_images-Slice: `8b370a8`; Review-Hardening: `0fe7544`, `a9750c2`; Follow-up-Batch: `80599f4`, `3bc1984`; Concern-Extraktion+Docs: `d372693`, `cef2403`; FK-Migration: `8e487ca`; Profile-E2E+BadgeCanvas: `17498c4`.)_

### Low Follow-ups (info, aus Verifikation — Session 2026-08-25b)
> Abgeschlossene Punkte entfernt: FE1-F2 (bereits vorhanden `80599f4`), FE1-F3 (Epsilon `80599f4`), FE1-F4 (host-Cache `80599f4`), E2E-Hygiene badge_images (`3bc1984`), BE-R1-F2 (RV-S3 Guard), BE-R1-F1 (FK-Migration `8e487ca`), DOC-H-F1/F2 (bereits korrekt), DOC-H-F3 (Vollpfad `cef2403`), BE-R1-F3 (by-design: Tests nutzen `:memory:`, sqlite-Datei ist Dev-Artefakt).
- [ ] **PDF-VISION (Pipeline-Learning, 2026-08-26)** — dompdf malt keinen weißen Seitenhintergrund → transparente Pixel erscheinen im PNG schwarz. Verifikations-Pipeline daher **zweistufig** (ImageMagick 7 kombiniert Flags nicht mit PDF-Input): `magick -density 200 x.pdf x-step.png && magick x-step.png -background white -alpha remove -alpha off x.png`. Optional robuster: `background-color:#ffffff` auf body/@page im Badge-HTML. Vision-Provider kann flaky sein → Fallback: PNGs per Read-Tool selbst analysieren.

### Full-Repo-Review 2026-08-26 (seit 2026-08-20) — Follow-ups (Verdict APPROVED, keine critical/high)
> Alle Punkte abgeschlossen (RV-S1 `0fe7544`, RV-S2/RV-A1/RV-U1 `8b370a8`, RV-S3 `a9750c2`, RV-S4/RV-A2/RV-U2 `0fe7544`, E2E-Hygiene badge_images `3bc1984`, RV-U3 dokumentiert `17498c4`).

### PDF-visuelle-Verifikation (Überlegungen, 2026-08-26)
> Ziel: generierte Badge-/Ausweis-PDFs genauso visuell verifizieren wie UI-Screenshots (Vision-Agent gegen Checklist:
> QR-Position, Feld-Überlappung, Abschneiden, Kontrast, Skalierung). Besonders relevant für **FE1** (Render-Kontrakt).

- **Tool-Befund lokal (macOS):**
  - ✅ **`magick` + Ghostscript (10.07.1) installiert und verifiziert** (User hat `brew install ghostscript`
    ausgeführt): `magick -density 200 t.pdf t-magick.png` rendert sauber — **primäre Methode** (hohe DPI,
    A6-Test: 1165×827 px vs. 420×298 @72dpi via sips → Schrift/QR-Details für die Vision-Analyse gut lesbar).
  - `sips` (macOS-Bordmittel) funktioniert ebenfalls out-of-the-box (nur erste Seite, ~72dpi) → **Fallback**.
  - `pdftoppm` (poppler) nicht installiert — nur relevant für den CI-Pfad (`poppler-utils` im E2E-Image).
- **Fixture-Pfad:** Badge-PDF wird backend-seitig erzeugt (dompdf ^3.1 verifiziert): entweder über den auth-geschützten
  Badge-Endpoint im laufenden Dev-Stack oder per PHPUnit/Artisan-Fixture in eine temp Datei gerendert → `sips` → PNG.
- **SOLL-Pipeline (FE1-Verifikation + künftige PDF-Änderungen):**
  1. Badge-PDF generieren (Dev-Stack/Fixture), 2. `magick -density 200 x.pdf x.png` (primär, Multi-Page-fähig) bzw.
     `sips -s format png` als Fallback, 3. PNG(s) an `vision`-Subagent mit PDF-Checkliste (QR unten rechts 20×20 mm,
     Felder ohne Überlappung/Abschneidung, Font-Skalierung, Rückwärtskompatibles Default-Layout),
  4. Findings-Report wie beim UI-Review (critical/high blockieren APPROVED), 5. bei Fixes: neu rendern + old-vs-new-Diff.
- **CI (optional, Follow-up):** für automatisierte PDF-Vision-Checks im E2E-Job `poppler-utils` (pdftoppm) ins
  `deployment/Dockerfile.e2e` aufnehmen — nicht blockierend für FE1, lokale Verifikation genügt zunächst.

### Bewusst NICHT in diesem Batch
- P7 Go-Live (weiterhin auf User-Freigabe) · finale User-Abnahme
- P5-F4 Queue-Integration (Go-Live-Infra, Post-MVP) · Feld-Editor-Umsetzung erst nach SOLL-Spec-Verifikation

---

## 🛠️ Session 2026-09-19 — Domain-Ordner Media-Layout + Event-Typen + Team-Teilnehmer

> User-Auftrag: Mandanten-Bilder auf `<MEDIA_ROOT>/<domain>/…`-Layout mit Root-Fallback
> umstellen (Caddy liefert direkt aus), Event-Typen als mandant-spezifische Tabelle mit
> Presets, Team/Vereins-Logos + Versus-Teilnehmer (Name+Bild, Heim-Default-Ort), Venue-Liste
> mit Meilen-Autocomplete prüfen. Entscheidungen (interaktiv): MEDIA_ROOT `/srv/media`,
> Domain-Key = normalisierter Request-Host, Personenbilder bleiben privat, Doku inklusive.
> Plan: `.opencode/plan/media-domain-layout.md`. Orchestrierung nach §5 (max. 2 parallel,
> nur disjunkte Dateien, Konsolidierung auf `main`, keine `fix/*`-Branches, `git add` nur
> explizite Pfade). Noch nicht live → Migrationen dürfen erweitert werden (D17).

### Ergebnis (abgeschlossen + §5-verifiziert; Voll-Suite 1039 grün, Pint/Compose/Caddy grün)
- **W1** MediaPathService + media-Disk (`3ef079a`), W1-F1 (`119a293`); W1-F2..F4-Lows in W6/W7 + LOW-Bündel eingearbeitet.
- **W2** event_types + Admin-CRUD (`bb74671`), W2-F1 (`aae133e`/`51aa937`), W2-F3 UTF-8-Härtung (`2e31643`), W2-F4 (`f041b87`).
- **W3** Preset-Schema (`f9979d3`) · **W4** Team-Logo + Event-Teilnehmer (`e56d8fe`), W4-F1 (`dbb6886`).
- **W6** Services + Backfill (`47b784b`), W6-F1/F2 (`70e67cd`), W6-F4 (`f041b87`); W6-F3-Lows in W11-F1 eingearbeitet.
- **W7** Caddy-Snippet `media_overrides` (`fc84ec1`, high-Re-Fix `d9e9960`), W7b global (`649a3ed`, inaktiv).
- **W11** Header-Delivery + WebP (`d2eb07f`/`2811f16`), W11-F1 (`7f57954`), W11-F2 (`42a9266`).
- **M2** (`3496fc7`) · **M3** (`d14bdda`) · **M4** (`d8c63fb`) · **LOW-Bündel** (`b7c037a`) · **W9**-Schlusscheck §5-APPROVED.
- **W5** Venue-Analyse erledigt + entschieden (KEIN Geo) → Umsetzung als W12 · **W10** WebP-Planung in W11 gemündet · **W8** Doku-Paket committet.

### Offene Punkte
- [ ] **W12 — Venue-Stammdaten** (geparkt, nach W5-Entscheidung: mandant-weite Liste, KEIN Geo, Suche nach Verein/Ort): `venues`-Tabelle + Admin-CRUD + Combobox mit Freitext-Fallback, `events.venue_id` nullable ergänzend zu `venue`; PHPUnit + E2E.
- [ ] **Dev-DB-Hinweis (W6-F3-Rest):** einmalig `migrate:fresh --seed` (sort_order-Schema).
- [ ] **Lernpunkt Parallel-Tests:** Voll-Suite NICHT parallel in 2 Subagenten laufen lassen (`Storage::fake` teilt `storage/framework/testing/disks/*` → Cross-Prozess-Race, 3 flaky Failures beobachtet). Suite immer nur in EINEM Subagenten zur Zeit. *(Kandidat für dauerhafte Regel in `AGENTS.md` §7 — nicht verschoben.)*

### Reihenfolge
W1 → W2/W4 (disjunkt, parallel ok) → W3/W5 (Analyse) → W6 → W7 → W8 → W9.

---

## 🔍 Whole-Project Code Review — 2026-09-26 (AKTUELLER PLAN)

> **Methode:** 6 Review-Subagenten (Allocation-Engine · Backend Auth/Security · Frontend-Logik ·
> Frontend-UI/Tests · Data-Layer/Services · Infra/CI) über den **kompletten** Stand
> (`1b66e00`, ~14.4k LOC Backend + ~12.8k LOC Frontend). Top-Befunde vom Build-Agent
> **nachimitiert** (CSRF-Pfade, `putFileAs`-Reihenfolge, `SameSite`, `throw=false`,
> Method-Override, NULL-Ordering, E2E-Assertion, zod-`path`, Spread-Reihenfolge).
>
> **User-Auftrag:** Findings dokumentieren, `features/`-SOLL nachziehen, **alle** Fixes
> umsetzen (unabhängig vom Severity). Orchestrierung nach §5: 10 Work-Pakete mit
> **disjunkten Ziel-Dateien**, max. 2 parallel, jede Umsetzung mit separatem Verifikator.
>
> **Bekannte Altlast (nicht in diesem Batch):** `W12 Venue-Stammdaten` (geparkt, s. o.).

### Entscheidungen (Build-Agent, aus Review abgeleitet)

| # | Thema | Entscheidung | Begründung |
|---|---|---|---|
| R-D1 | CSRF | `SameSite=Lax` **plus** Origin-Check auf state-changing Requests | SPA + API sind same-origin (relatives `fetch('/api/…')`, `/api*` im selben Caddy-Site-Block) → `None` war nie nötig. Origin-Check bleibt als Defense-in-Depth für Split-Deployments. |
| R-D2 | `trustProxies` | `at()`-Trust für `127.0.0.1/::1` + env-gatebares `TRUSTED_PROXIES` | Ohne das keyen **alle** Rate-Limiter auf die Proxy-IP → ein Angreifer 429t alle Tenants. Zusätzlich `isSecure()` dauerhaft `false` → `http://`-Links in Mails/PKPASS. |
| R-D3 | QR-Token | Token-Format `v2` = `base64url(id . '.' . hmac(secret, "v2:"+id) . '.' . mandant_id)`; `parse()` mandant-scoped, Rotation über `APP_PREVIOUS_KEYS` | Behebt **drei** Befunde mit einer Ursache (kein Tenant-Binding, `APP_KEY`-Rotation killt Tokens, Backfill kann nicht reparieren) inkl. Cross-Tenant-Leak via `/api/verify`. |
| R-D4 | Allocation-Atomarität | `DB::transaction` + `lockForUpdate()` auf der Accreditation-Zeile für **alle** Status-Writes der Engine; Bulk-Ablauf komplett in **einer** Transaktion (Mails nach `commit()`) | `features/accreditation/01-allocation-engine.md:27-29` garantiert „Quota wird nie überschritten" — das ist mit Read-then-Write nicht haltbar. Advisory-Locks wären in SQLite nicht verfügbar → Row-Lock ist der portable Weg. |
| R-D5 | Sub-Akkreditierung | Haupt-Antrag `denied` → alle Sub-Anträge `denied` („Haupt-Akkreditierung entzogen"), Wallet-Pass 410/404 | D9 („Sub nur auf genehmigter Haupt-Akkreditierung") war nur beim Apply erzwungen; nach Widerruf blieb ein gültiger Parkausweis. |
| R-D6 | NULL-Ordering | `->orderByRaw('col asc nulls last')` (PG + SQLite ≥ 3.30) | Portabilitätsregel §2: PG sortiert `ASC` NULLs **last**, SQLite **first** — Prod/Test-Divergenz auf zwei Live-Listen. |
| R-D7 | Media-Delete | `MediaStorage::delete()` liefert `bool`; Callers löschen die DB-Spalte **nur** bei Erfolg, sonst 500 | Bei `throw=false` wurde ein fehlgeschlagenes `unlink` als Erfolg gemeldet — Bild bleibt auf MEDIA_ROOT und wird weiter von Caddy ausgeliefert. |
| R-D8 | `APP_KEY` in Prod | Compose `${APP_KEY:?…}` (kein Default) **plus** Boot-Guard in `AppServiceProvider` | Ein **funktionierender** Default-Key im Repo = alle `Crypt`-Payloads + QR-Tokens forgerbar, und zwar ohne jede Fehlermeldung. |
| R-D9 | `smtp_config` | Cast auf `encrypted:json` | SMTP-Passwörter lagen im Klartext in einem JSON-Feld; `MandantResource` maskierte sie nur am API-Rand. |
| R-D10 | Prod-Deploy | `prod`-Profil als **nicht einsatzbereit** dokumentiert, bis `USER`/`vendor`/`APP_ENV`/Netzwerk-Alias behoben sind; Infra-Doku in `features/03-caddy-brand-files.md` nachgezogen | Das Profil startet aktuell als root, ohne `vendor/` und mit `APP_ENV=local` (⇒ JWT-Cookie **ohne** `Secure` + Default-Super-Admin im Seeder). |

### Work-Pakete (disjunkte Ziel-Dateien, max. 2 parallel)

#### 🔴 WP-1 — Auth-Härtung: CSRF, Proxy-Trust, Boot-Guard
**Dateien:** `backend/bootstrap/app.php`, `backend/app/Http/Controllers/Controller.php`,
`backend/app/Providers/AppServiceProvider.php`, `backend/config/session.php`,
`backend/app/Http/Middleware/*` (neu: Origin-Guard), `backend/tests/Feature/*` (neu)
- [ ] **WP-1-a (CRIT, R-D1):** `Controller::respondWithToken()` → `SameSite=Lax` in **allen**
  Umgebungen; Docblock korrigieren (Same-Origin-Begründung). `SameSite=None`+`Secure` nur
  via explizitem Opt-in `JWT_CROSS_SITE_COOKIE=true` (Default aus).
- [ ] **WP-1-b (CRIT, R-D1):** Middleware `EnsureSameOrigin` auf allen state-changing
  API-Routen (`POST|PUT|PATCH|DELETE` unter `/api/*`, **inkl.** `/api/auth/*`): `Origin`
  fehlt → 403; `Origin`-Host ≠ aufgelöster Mandant-Host → 403. Registrierung/Login
  ausgenommen, wenn kein Cookie betroffen ist (Login setzt das Cookie selbst).
- [ ] **WP-1-c (HIGH, R-D2):** `trustProxies` in `bootstrap/app.php` (`at()` für
  Loopback, plus `TRUSTED_PROXIES`-env für Prod) + `proxies`/`headers` korrekt gesetzt.
- [ ] **WP-1-d (MED, #12):** Host-Allow-List aus `bootstrap/app.php` env-gatebar
  (`TRUSTED_HOSTS`); Dev-Wildcards `^(.+\.)?test$`/`^(.+\.)?localhost$` **nur** ohne
  `APP_ENV=production`; toter Eintrag `localhost:5173` entfernen (Port wird von
  `getHost()` nie geliefert); `MandantDomain`-Lookup **cachen** (TTL analog
  `MandantContext`, `mandants.cache_ttl`) und `catch` darf **nicht** still auf die
  Dev-Liste degradieren → im Prod-Modus 500 mit Log, nicht 400 für alle Tenants.
- [ ] **WP-1-e (HIGH, R-D8):** Boot-Guard: `APP_ENV=production` + Default-/leerer
  `APP_KEY` → Log-Critical + Abbruch. Test: `production`-Boot mit
  `base64:AAAA…`-Key wirft.
- [ ] **WP-1-f (MED, #24):** `config/session.php` `secure` → `env('SESSION_SECURE_COOKIE')`
  Default `!local`; `SESSION_SECURE_COOKIE` in `.env.example` ergänzen (via WP-5).
- [ ] **Tests WP-1:** Feature-Tests für Cookie-Flags je `APP_ENV`; Origin-Guard
  (403 bei Fremd-Origin, 403 bei fehlendem Origin, 200 bei same-origin, **403 bei
  `_method`-Override mit Fremd-Origin**); Proxy-Trust ⇒ `isSecure()` + `$request->ip()`
  aus `X-Forwarded-For`; Boot-Guard; Session-Cookie-Flag.
- [ ] **Doku:** `features/auth/01-auth-and-roles.md` — SameSite-Semantik, Origin-Guard,
  `trustProxies`, `TRUSTED_HOSTS`, B3-Härtung, F6/F7-Rest-Risiken neu bewerten.

#### 🔴 WP-2 — QR-Token v2: Tenant-Binding, Key-Rotation, Badge-Export
**Dateien:** `backend/app/Services/QrTokenService.php`,
`backend/app/Http/Controllers/Api/VerifyController.php`,
`backend/app/Http/Resources/VerifyResource.php`,
`backend/app/Http/Resources/AdminApplicationResource.php`,
`backend/app/Services/BadgeRenderService.php` (nur `verifyUrl` + Render-Härtung),
`backend/app/Console/Commands/BackfillQrTokens.php`, `backend/tests/**`
- [ ] **WP-2-a (HIGH, R-D3):** Token-Format v2 mandant-gebunden (siehe R-D3). `parse()`
  liefert `[id, mandantId]` und prüft `hash_equals` über **beide** Claims; `mandant_id`
  muss zum aufgelösten `MandantContext` passen, sonst 404. `VerifyController` nutzt
  zusätzlich eine mandant-scoped Query (`->whereHas('accreditation', …)`) als
  Defense-in-Depth.
- [ ] **WP-2-b (HIGH):** Key-Rotation: `secret()` probiert `config('app.previous_keys')`
  durch; `make()` **mintet neu**, wenn der gespeicherte Token mit keinem Key mehr
  verifiziert (statt ihn blind zurückzugeben) ⇒ Bestands-Badges bleiben gültig bzw.
  werden self-healing. `accreditation:backfill-qr-tokens` deckt v1-Tokens ab.
- [ ] **WP-2-c (HIGH):** `AdminApplicationResource`/`BadgeRenderService::verifyUrl`
  konsistent auf v2 umstellen; Docblock-Korrektur („computed deterministically on
  READ" ist falsch).
- [ ] **WP-2-d (MED, #18):** Badge-PDF-Export: `approvedApplications()` chunken,
  Base64-Portraits nicht für **alle** Karten vorab materialisieren (stream/limit),
  `BadgeImage`-Lookup pro Run cachen (N+1) und Tenancy-Filter **immer** anwenden
  (aktuell via `->when(MandantContext::hasCurrent(), …)` fail-open).
- [ ] **Tests WP-2:** Cross-Mandant-Token → 404 (kein Datenleck); v1-Token mit
  altem Key → weiterhin gültig; `APP_KEY`-Rotation ⇒ self-healing Re-Mint;
  `hash_equals`-Negativfall; Falsch-`mandant_id` im Token → 404; Portraits von
  Fremd-Mandanten werden nie eingebettet.

#### 🔴 WP-3 — Allocation-Atomarität + Sub-Akkreditierungs-Abhängigkeit
**Dateien:** `backend/app/Services/AllocationService.php`, `SubAllocationService.php`,
`AllocationRules.php`, `AllocationResult.php`,
`backend/app/Http/Controllers/Api/WalletController.php`,
`backend/app/Http/Controllers/Api/SubAccreditationController.php`, `backend/tests/**`
- [ ] **WP-3-a (HIGH, R-D4):** `approvedCount()` + Status-Write unter
  `DB::transaction` + `lockForUpdate()` auf der Accreditation-Zeile. Betrifft
  `approveSelection`, `approveAllEligible`, `approveApplication`, `denyApplication`
  **und** die Sub-Pendants. Regressionstest: Quota-Überschreitung unmöglich
  (Parallel-Test via zwei Requests auf getrennten Verbindungen, oder
  Unit-Test auf `lockForUpdate`-Nutzung + Doku-Assertion).
- [ ] **WP-3-b (MED):** Bulk-Ablauf atomar: `markApproved` → `issueQrTokens` →
  `markDenied(quota)` → `markDenied(blacklist)` in **einer** Transaktion; Mail-Dispatch
  **nach** `commit()`. Verhindert „3 überzählige Anträge bleiben ewig `requested`"
  bei Teilfehler auf manuellen Akkreditierungen.
- [ ] **WP-3-c (MED, #13, R-D5):** Haupt-Antrag `approved → denied` (Widerruf) ⇒
  alle `approved`-Sub-Anträge werden `denied` (eigener `reason`), **atomar** mit dem
  Haupt-Status. `WalletController`-Issue prüft zusätzlich den Haupt-Status und
  antwortet 404/410 statt eines gültigen PKPASS.
- [ ] **WP-3-d (LOW, #14):** Sub-Statuswechsel mailen (analog Haupt-Antrag) **oder**
  bewusst als nicht implementiert in `features/` dokumentieren + `resend`-Route
  für Sub-Applications ergänzen (Entscheidung im Feature-Doku festhalten).
- [ ] **Tests WP-3:** Quota nie überschritten (Race); Widerruf kaskadiert; Wallet-Pass
  nach Widerruf ungültig; Bulk-Transaktions-Rollback bei Absprache in der
  Deny-Phase (Mock/Queue-Assertion, kein echter DB-Fehler nötig).
- [ ] **Doku:** `features/accreditation/01-allocation-engine.md` — Kernregel 3 um
  „atomar unter Row-Lock" ergänzen, §Offene Punkte (Blacklist-CRUD + VIP-Setzung
  existieren inzwischen) aufräumen, R-D4/R-D5 festhalten.

#### 🔴 WP-4 — Media-Write-Safety (UserMedia + delete-Semantik)
**Dateien:** `backend/app/Services/UserMediaService.php`, `MediaStorage.php`,
`MandantMediaService.php`, `TeamMediaService.php`, `EventTypeMediaService.php`,
`BadgeImageService.php`, `backend/tests/**`
- [ ] **WP-4-a (HIGH, #3):** `UserMediaService::store()` auf **write-then-delete**
  umstellen (neue Datei zuerst, `putFileAs() === false` ⇒ `RuntimeException`, **kein**
  `UserMedia::create(['path' => (string) false])`); `replaceSingular()` erst nach
  erfolgreichem Write. Quota-Berechnung bleibt vorab (siehe Doku), Delete nach dem Write.
- [ ] **WP-4-b (MED, R-D7):** `MediaStorage::delete()` → `bool` (Erfolg = Datei danach
  nicht mehr existent). Alle Callers (`MandantMediaService::destroy`,
  `TeamMediaService::destroy`, `EventTypeMediaService::destroy`,
  `BadgeImageService::destroy`) löschen die DB-Spalte **nur** bei `true`, sonst
  `RuntimeException`/500 mit Log. `deleteAlternateExtensions()` analog.
- [ ] **WP-4-c (LOW):** Doku-Kommentar in `MediaStorage` um `throw => false`-Kontra
  ergänzen; `IMAGE_EXTENSIONS` als Konstante bleibt Single Source.

#### 🟠 WP-5 — Prod-Deploy: Compose/Dockerfile
**Dateien:** `deployment/docker-compose.yml`, `deployment/Dockerfile`,
`deployment/Dockerfile.e2e`, `backend/.env.example`
- [ ] **WP-5-a (CRIT, R-D8):** `APP_KEY: ${APP_KEY:?APP_KEY is required for the prod profile}`.
- [ ] **WP-5-b (HIGH, #8/R-D10):** `USER`-Direktive in `Dockerfile` (non-root
  PHP-FPM); `vendor/` + `composer install --no-dev` ins Image; `healthcheck` auf
  `backend`; `bootstrap/cache` + `storage` beschreibbar.
  **Caddy-Erreichbarkeit (korrigiert nach eigener Nachprüfung):** der `backend`-Service
  published **keinen** Port und hängt am projektinternen, host-fremden Netz
  `accriditation_internal`, während `features/03-caddy-brand-files.md:105` als Upstream
  `accreditation_backend:9000` vorschreibt und Caddy laut Go-Live-Plan **auf dem Host**
  unter `~/dev/caddyfile/Caddyfile` läuft. Der Upstream ist damit **nicht auflösbar** —
  unabhängig von Alias-Resolution. Auflösen via **entweder** `127.0.0.1:9000:9000`
  publishen + Upstream auf `127.0.0.1:9000` **oder** Netz als `external: true` mit
  festem `name:` deklarieren und Caddy-Container anhängen. Beides im Compose-**und**
  Caddy-Doku-Block festhalten; Default ist Host-Caddy ⇒ Publish auf Loopback.
- [ ] **WP-5-c (HIGH, #20/#21):** `APP_ENV: ${APP_ENV:-production}` im prod-Profil;
  DB-/Admin-Passwörter aus env ohne Default (`${DB_PASSWORD:?…}`).
- [ ] **WP-5-d (MED, #22):** Mailpit/DB-Ports auf `127.0.0.1:` binden (analog `db`);
  Mailpit **aus** dem prod-Profil herausnehmen (eigener Override-Block).
- [ ] **WP-5-e (MED, #23):** `MEDIA_ROOT_HOST` — Existenz-Check dokumentieren +
  `:rw`-Kommentar; im README/Compose-Kommentar auf das Fehlverhalten hinweisen.
- [ ] **WP-5-f (LOW, #16):** `Dockerfile.e2e`-Build-Args an den Lockfile-Stand
  angleichen (`PLAYWRIGHT_VERSION`, `PNPM_VERSION`) + `shasum -c` gegen
  `SHASUMS256.txt` für den Node-Tarball.
- [ ] **Tests WP-5:** `docker compose config` + `docker compose --profile prod config`
  validiert; `caddy validate` bleibt manuell (Snippet außerhalb des Repos).

#### 🟠 WP-6 — Schema + Portabilität
**Dateien:** **neue** Migrationen unter `backend/database/migrations/`,
`backend/app/Http/Controllers/Api/Portal/PortalController.php`,
`backend/app/Models/Event.php`,
`backend/app/Http/Resources/AdminSubApplicationResource.php`,
`backend/app/Http/Controllers/Api/Admin/EventTypeController.php`,
`backend/app/Models/Mandant.php`, `backend/config/app.php`
- [ ] **WP-6-a (MED, R-D6, #16):** `->orderByRaw('date asc nulls last')` /
  `…('sort_order asc nulls last')`; Regressions-Tests, die die Reihenfolge
  auf SQLite **und** die Query-Form pinnen.
- [ ] **WP-6-b (MED, #15):** `role_user` — Unique-Constraint, der NULL-Scopes
  tatsächlich greift. **Nachgewiesen:** `role_user_scope_unique` auf
  `(user_id, role_id, mandant_id, team_id)`; `super_admin` hat `mandant_id` **und**
  `team_id` NULL, `mandant_admin`/`user`/`verifier` haben `team_id` NULL — NULLs sind
  in PG **und** SQLite distinct, der Unique feuert für keine existierende Rolle.
  **Präzedenz im Repo:** `0001_01_01_000000_create_users_table.php:27-30` dokumentiert
  denselben NULL-Distinct-Fall explizit — dort ist er **gewollt** (mehrere globale
  Accounts legal), bei `role_user` ist er ein Fehler ⇒ die gleiche Technik darf nicht
  blind kopiert werden. Portable Optionen (beide Engines unterstützen ANSI-SQL):
  (a) Unique-Index über `COALESCE(mandant_id,0)`/`COALESCE(team_id,0)` per
  `DB::statement` (Index-Name für `down()` mitgeben — `down()` wird nie ausgeführt);
  (b) App-Level-Guard in `RoleUser` (Model-Event/`RoleUser::create`-Wrapper) +
  `rolesPayload` dedupliziert, DB-Index als zusätzliche Schicht für nicht-Null-Scopes.
  Migration + Tests: doppeltes globales `super_admin` bzw. doppelte Registrierung →
  422/kein Duplikat, `team_admin` mit Team weiterhin DB-geschützt.
- [ ] **WP-6-c (LOW, #19):** Index auf `users.email` allein (Login-Hot-Path
  ohne `MandantContext`).
- [ ] **WP-6-d (LOW, R-D9, #27):** `Mandant::$casts['smtp_config'] = 'encrypted:json'`
  + Hinweis auf Re-Encryption falls Prod-Daten existieren.
- [ ] **WP-6-e (LOW, #25):** `APP_MAINTENANCE_DRIVER` Default auf `database`
  (`.env.example` entspricht; `file` nur als bewusste Dev-Option).
- [ ] **WP-6-f (LOW, #11):** `AdminSubApplicationResource` — `relationLoaded`-Check
  **vor** dem Lazy-Load.
- [ ] **WP-6-g (LOW, #12):** `EventTypeController::store` — Spread **nach**
  `'mandant_id'`, analog zu `CategoryController`/`EventController`.
- [ ] **Tests WP-6:** je Migration ein Feature-Test; Suite auf SQLite **und**
  Postgres-Gate (§Go-Live-Plan) grün.

#### 🟠 WP-7 — Frontend-Formulare + E2E-Korrektheit
**Dateien:** `frontend/src/pages/admin/eventFormUtils.ts`,
`frontend/src/pages/admin/approvalFormUtils.ts`,
`frontend/src/pages/admin/EventForm.tsx`,
`frontend/src/pages/admin/BlacklistForm.tsx`,
`frontend/src/pages/admin/ApprovalsPage.tsx` (nur `BlacklistForm`-Re-throw-Block),
`frontend/tests/e2e/sub-accreditation.spec.ts`,
`frontend/tests/e2e/admin-event.spec.ts`, zugehörige `*.test.ts`
- [ ] **WP-7-a (MED, #28/#29):** Objekt-Level-`.refine()` → `.superRefine(..., path: […])`
  (Muster `accreditationFormUtils.ts:19-31`), damit `zodResolver` den Fehler unter
  `deadline_end` bzw. `root`/passendem Feld keyt und `EventForm`/`BlacklistForm` ihn
  rendern. Regressionstest: Deadline-Order-Verstoß ⇒ Fehlertext **sichtbar**.
- [ ] **WP-7-b (HIGH, #10):** `sub-accreditation.spec.ts:94` — Assertion auf den
  ICU-Text (`Platz frei`/`Plätze frei`) aktualisieren; Spec grün.
- [ ] **WP-7-c (MED, #32):** `BlacklistForm`/`ApprovalsPage` — Submit-Handler
  wirft nicht mehr, sondern `return false`; kein unhandled rejection.
- [ ] **WP-7-d (MED):** `admin-event.spec.ts:56` — Assertion auf `editedTitle`
  (nicht `uniqueTitle`) + `toHaveCount(0)` für den alten Namen.
- [ ] **Tests WP-7:** `pnpm vitest run` grün; `pnpm test:e2e:smoke` + betroffene
  Feature-Specs grün.

#### 🟠 WP-8 — Frontend-UI: i18n-Zähler, a11y, LOW-Härtung
**Dateien:** `frontend/src/locales/{de,en}/messages.po`, `frontend/src/pages/**`,
`frontend/src/components/MediaField.tsx`, `frontend/src/App.tsx`,
`frontend/src/pages/admin/AdminLayout.tsx`, `frontend/src/logic/useAuth.ts`
- [ ] **WP-8-a (MED, #30):** 8 hardcodierte deutsche Zähler → ICU-Plural-Messages
  (DE+EN), `lingui:extract` + `lingui:compile`; obsolete `#~`-Einträge sauber
  aufräumen. E2E-Assertions, die die deutschen Literale prüfen, mitziehen.
- [ ] **WP-8-b (MED, #34):** Alle 8 `modal`-Instanzen: `showModal()`-Semantik
  (echtes `<dialog open>` bzw. `showModal()`), `Escape`-Close, Fokus-Management
  (`aria-modal`, Fokus hinein/zurück), Hintergrund `inert`.
  **Mechanik (am echten daisyUI 5.7.38-CSS verifiziert):** `.modal.modal-open` ist ein
  **reiner CSS-Zustand** (`pointer-events:auto; visibility:visible; opacity:1;
  background-color:oklch(0 0 0/.4)`) — ohne `open`-Attribut und ohne `showModal()` gibt
  es **keinen** Top-Layer, **kein** natives `cancel`/Escape und **keinen** Fokus-Fang;
  `.modal::backdrop{display:none}`. Auf natives `<dialog>` umstellen ⇒ daisyUI regelt
  `.modal[open]` bereits mit (`pointer-events:auto;visibility:visible;…`) ⇒ Opt-in-Opt-out
  bleibt erhalten.
- [ ] **WP-8-c (MED, #11):** Admin-Drawer keyboard-reachable (`<button>`-Trigger
  mit `aria-expanded`/`aria-controls` statt `<label htmlFor>`), `Escape`-Close.
  **Mechanik (verifiziert):** `.drawer-side` ist per Default
  `pointer-events:none; visibility:hidden; opacity:0` und wird ausschließlich über
  `:where(.drawer-toggle:checked~.drawer-side)` sichtbar. `.drawer-toggle` ist ein
  `appearance:none; opacity:0; width:0; height:0`-**Checkbox**; das `<label htmlFor>`
  ist weder fokussierbar noch hat es eine Rolle ⇒ **kein** Tastaturweg zum Öffnen.
- [ ] **WP-8-d (LOW):** `BadgeTemplatesPage` Plural (`1 Felder`); `MediaField`
  `event.target.value` zurücksetzen; `VerifyPage` Token-Sync via
  `key={pathToken}`/Effect; `ApprovalsPage` VIP-State-Re-Sync; `MyAccreditationsPage`
  Wallet-Download mit Fehlerbehandlung (`ApiError`) statt `<a download>`;
  `MandantListPage` `https://`; `MandantDetailPage` `Number.isInteger`-Guard;
  `PortalHomePage` Wettbewerbs-Dropdown (exakt vs. Substring);
  `MandantForm` `setSmtpCleared` nur bei Erfolg; `App.tsx`/`AdminLayout`
  `handleLogout` mit `try/catch`; `useAuth` `isLoading`-Korrektur;
  `App.tsx` Catch-all-Route + `errorElement`; `App.test.tsx` Locale-Reset.
- [ ] **Tests WP-8:** `pnpm lint:fix && pnpm build` + `pnpm vitest run` grün;
  betroffene E2E-Specs grün; `check-i18n` grün.

#### 🟡 WP-9 — CI-Härtung
**Dateien:** `.github/workflows/*.yml`, `.github/dependabot.yml`,
`backend/phpunit.xml`, `backend/composer.json`, `frontend/pnpm-workspace.yaml`
- [ ] **WP-9-a (MED, #35):** E2E-Job baut **produktiv** (`pnpm build` + `pnpm preview`)
  statt `pnpm dev`; Dev-Server-Job bleibt für Iteration.
- [ ] **WP-9-b (MED, #36):** `@regression` (und die `@feature:*`-Specs) in CI
  aufnehmen — mindestens als nightly Job, um §7 durchzusetzen.
- [ ] **WP-9-c (MED, #39):** `<env name="APP_KEY">` fest in `phpunit.xml` +
  `failOnRisky="true"` + `failOnWarning="true"`.
- [ ] **WP-9-d (MED, #37):** `base-image.yml` — Digest-Pin statt `:8.5`-Tag
  (nightly-Rebuild entweder entfernen oder Tag nicht überschreiben).
- [ ] **WP-9-e (LOW, #38):** `automerge.yml` — `${{ }}` → `process.env.*`.
- [ ] **WP-9-f (LOW):** Dependabot um `github-actions` + `docker` erweitern
  (macht `notDocker` wieder sinnvoll); `paratest` auf `^` pinnen; `laravel/tinker`
  nach `require-dev`; `minimumReleaseAge` wieder aktivieren.

#### 🟡 WP-10 — Console-Commands: Chunking + Pfad-Härtung
**Dateien:** `backend/app/Console/Commands/MediaConvertToWebpCommand.php`,
`MediaPruneOrphansCommand.php`, `SendReminders.php`, `backend/tests/**`
- [ ] **WP-10-a (MED, #26):** `chunkById` statt `->get()` in allen drei Commands;
  `MediaPruneOrphansCommand` nicht `allFiles()` über den ganzen Baum.
- [ ] **WP-10-b (LOW, #10):** `isManagedPath()` validiert `segments[0]` als echten
  Mandant-Host (`MediaStorage::isHostSegment()`) bzw. `_tenants/<n>` mit gültiger
  Mandant-ID; unbekannte Root-Verzeichnisse nie löschen.

### Verifikations-Gate WP-1+WP-2 → CHANGES REQUIRED (2026-09-26, separater Verifikator)
> Suite 1124 grün / Pint PASS / Tenant-Isolation und Rotation **unabhängig per Probe
> bestätigt** (v2 + legacy v1 + tampter Claim + `/photo` ⇒ alle 404 ohne Datenleck,
> 22 CSRF-Angriffsvarianten geblockt). **1 high + 2 medium blockieren** → als eigene
> Fix-Todos delegiert (WF-1), Re-Verifikation folgt.

- [ ] **WF-1-a · HIGH · `Support/TrustedProxyConfig.php:80-87` + `bootstrap/app.php:29-32`:**
  `TRUSTED_PROXIES` wird **gelesen, bevor dotenv/config geladen sind.** Die Registrierung
  hängt an `afterResolving(HttpKernel::class, …)` und feuert bei Kernel-Auflösung — vor
  `LoadEnvironmentVariables`/`LoadConfiguration`. Belegt: `config()`-Branch wird nie
  betreten (`bound(config)===false`) und `Env::get('TRUSTED_PROXIES')` sieht nur den
  **Prozess**-env, nicht `.env` — obwohl `backend/.env.example:63-67` genau dort die
  Variable dokumentiert. **Folge:** der Wert aus `.env` wird **stillschweigend ignoriert**
  ⇒ Rate-Limiter-Collapse bleibt (ein Angreifer 429t alle Tenants) und `EnsureSameOrigin`
  vergleicht `Origin`-Scheme gegen `getScheme()` ⇒ bei TLS **jede** same-origin-Origin
  403. Tests decken es nicht, weil `TrustedProxyTest` nur `config()`-Overrides nutzt.
  **Fix-Richtung:** Trusted-Proxy-Registrierung aus `bootstrap/app.php` in einen
  Service-Provider-`boot()` verlagern (dort ist Config verfügbar) — nicht am
  `Env::get`-Fallback weiterdrehen. Regressionstest: Wert **über `.env`** ⇒ wirksam.
- [ ] **WF-1-b · MEDIUM · `AllocationService.php:303-314`:** N+1 **durch WP-2 eingeführt** —
  `issueQrTokens()` lädt `accreditation` nicht eager (v2-Token braucht `mandant_id`).
  Gemessen 10er-Bulk-Allocation: 38 Queries, davon 10 einzelne
  `select * from accreditations where id = ?`; bei 500 Badges 500 Extra-Roundtrips.
  Fix: `->with('accreditation:id,mandant_id')` — dieselbe Zeile, die in `BackfillQrTokens`
  bereits korrekt ist. (= WP-3-H1, hiermit verifiziert bestätigt.)
- [ ] **WF-1-c · MEDIUM · `bootstrap/app.php:56-71`:** nicht-leeres `TRUSTED_HOSTS`
  **verdrängt `localhost`/`127.0.0.1`/`::1`** ⇒ `GET http://127.0.0.1/up` = **400** ⇒
  Docker-`HEALTHCHECK`/LB-Probe ⇒ Container unhealthy / Restart-Loop — genau in der
  Härtungs-Konfiguration, die WP-1 einführt. Loopback **immer** ergänzen. Doku
  `features/auth/01-auth-and-roles.md:135` („bleibt immer") ist in diesem Fall falsch ⇒
  mitziehen oder Code korrigieren.
- [ ] **WF-1-d · LOW (mitnehmen, gleiche Dateien):**
  (a) `EnsureSameOrigin.php:69-73` — `EXEMPT_PATHS` (`auth/login`, `auth/register`) ist eine
  **unnötige Schwächung**: beide Routen brauchen kein Cookie, `SameSite=Lax` greift nicht,
  und ein Cross-Site-Formular trägt immer `Origin`+`Sec-Fetch-*`, die der generische
  Branch ohnehin ablehnt. Belegt: Login mit `Origin: https://evil.example` ⇒ **200**,
  Register ⇒ **201** (Signup-CSRF, Uploads in den Angreifer-Account). Exemption entfernen.
  (b) `QrTokenService.php:173-189` — ~0,01 % der v1-Token werden abgewiesen, wenn die
  Signatur einen `.`-Byte enthält **und** der Rest-Ziffern sind (über 40 000 Token: 4
  betroffen) ⇒ 404. Fail-closed, aber `features/badges-qr.md:121` behauptet das Gegenteil.
  (c) `features/badges-qr.md:171-172` — „denied behält Token ⇒ verifiziert als *entzogen*"
  gilt nur, solange der Token noch verifiziert; nach Rotation ohne `APP_PREVIOUS_KEYS`
  antwortet `/api/verify` 404 statt `{status: denied}`.
  (d) `Controller.php:38-39` + `config/jwt.php:292` — mit `JWT_CROSS_SITE_COOKIE=true` in
  `local` wird `SameSite=None` **ohne** `Secure` emittiert ⇒ Chrome ≥84/Firefox ≥96
  verwerfen den Cookie ⇒ Auth bricht lautlos. `config/jwt.php` behauptet das Gegenteil.

### Verifikations-Gate Batch (WP-1+WP-2+WF-1+WP-5) → CHANGES REQUIRED (2026-09-26, 2. separater Verifikator)
> **Sicherheits-Kern bestätigt:** CSRF nicht umgehbar (62 Angriffsvarianten, `Sec-Fetch-*`-
> Fallback, `_method` **und** `X-HTTP-Method-Override`, Login/Register jetzt gedeckt),
> Tenant-Isolation v2 + legacy v1 + `/photo` ohne Datenleck, `.env`-autoritativer
> Proxy-Trust, Loopback-erhaltende Host-Allow-List, lauter `APP_KEY`-Guard,
> Deployment-Härtung. **Kreuzkontamination der zwei überlappenden Agenten: keine Spuren**
> (keine duplizierten Blöcke, kein Dead Code, keine Debug-Reste, keine Scratch-Files,
> Config-Keys konsistent geschrieben/gelesen). `Dockerfile` besteht
> `docker buildx build --check`. **2 blockierende Befunde:**

- [ ] **WF-2-a · HIGH · `deployment/docker-compose.yml:41-46` + `scripts/e2e-up.sh:36`:**
  WP-5 hat `DB_USERNAME`/`DB_PASSWORD` auf `db` **verpflichtend** gemacht, aber das einzige
  dokumentierte Local-Setup-Skript exportiert **keine** davon und ruft ohne `--profile`:
  `bash scripts/e2e-up.sh` scheitert sofort (`required variable DB_PASSWORD is missing a
  value`). Selbst mit exportierten Variablen startet **nur** `db` ⇒ **Mailpit** (und damit
  der E2E-Mail-Flow) kommt gar nicht hoch. Der Compose-Kommentar L42, der behauptet, das
  Skript exportiere das Passwort, ist **falsch**.
  **Fix:** Dev-Werte im Skript exportieren **und** `--profile mail` (oder entsprechendes
  Default-Profil) mitgeben — alternativ ein dev-only Compose-Override, das `db`
  wieder defaultet. Prod-Härtung (kein Default) bleibt, Dev-Bequemlichkeit darf davon
  nicht profitieren ⇒ **Trennung sauber herstellen** (z. B. Override-Datei für Dev).
- [ ] **WF-2-b · MEDIUM · `features/03-caddy-brand-files.md:105` +
  `deployment/caddy-media-api-accel.Caddyfile:23,28`:** Compose published jetzt
  `127.0.0.1:9000`, beide normativen Snippets schreiben aber weiter
  `accreditation_backend:9000` (unauflösbar), und der neue SOLL-Absatz sagt noch
  „published keinen Port" und vertagt die Entscheidung ⇒ `features/` **widerspricht** dem
  ausgelieferten Compose. Wer den dokumentierten Site-Block kopiert, bekommt ein
  fehlschlagendes `/api*`-Upstream. **Fix:** beide Snippets auf `127.0.0.1:9000`
  umstellen, Absatz als **getroffene Entscheidung** neu formulieren (Default = Host-Caddy
  ⇒ Loopback-Publish; Alternative external-Netz bleibt als Option).
- [ ] **WF-2-c · LOW (mitnehmen):**
  (a) `bootstrap/app.php:52` — `trustHosts()` wird mit Default `$subdomains = true`
  aufgerufen ⇒ das Framework hängt in **auch** production `^(.+\.)?<APP_URL-Host>$` an,
  exakt die Wildcard-Klasse, die WF-1 gerade ausschließen wollte (bounded: nur `/up`).
  Kommentar ist falsch ⇒ `false` übergeben + Test.
  (b) `Admin/MandantController.php:106-111` — `destroy()` ruft `MandantContext::forgetHostnames()`
  nicht ⇒ Hostname einer **gelöschten** Mandant bleibt bis `MANDANTS_CACHE_TTL` (3600 s)
  allow-listed. Der eine Hostname-schreibende Pfad, den der Batch übersehen hat.
  (c) `backend/.env.example` — `APP_PREVIOUS_KEYS` undokumentiert, obwohl die
  Rotationsgarantie daran hängt (= WP-5-H2).

### WP-5 Ergebnis (2026-09-26, Implementer-Report) + offene Entscheidungen
> **Kollision dokumentiert:** WP-5 und der WF-2-Lauf haben **gleichzeitig** dieselben
> `deployment/`-Dateien angefasst (Server-Restart mitten im Lauf). WP-5 hat beide Stände
> nach dem letzten Write neu gelesen und gegen den aktuellen Plattenstand verifiziert —
> nichts ging verloren, aber **`Dockerfile.e2e` stammt vollständig vom WF-2-Lauf**, nicht
> von WP-5. ⇒ **Finale Verifikation MUSS den kombinierten Diff prüfen**, nicht die
> Einzel-Commits.

**Gelandet:** `APP_KEY: ${APP_KEY:?…}` (Default raus) · `APP_ENV: ${APP_ENV:-production}` ·
`DB_USERNAME`/`DB_PASSWORD` verpflichtend (auch auf `db`, damit die beiden Services nicht
auseinanderlaufen) · mailpit → `profiles: ["mail"]` + `127.0.0.1`-Bind · backend ohne
`../backend`-Bind-Mount, `127.0.0.1:9000:9000` published, FPM-`healthcheck` ·
`Dockerfile` mit `USER www-data` (UID 33 live verifiziert), `vendor/` eingebackt (0 Dev-Packages),
**kein** `.env`/`tests/` im Image, `MEDIA_ROOT`-Guard ⇒ **Exit 78** mit Aktionshinweis,
`APP_SOURCE`-Schalter statt plain `COPY backend/` (sonst bricht `base-image.yml`).

**Live validiert (Docker 29.5.3 / Compose v5.3.1 / hadolint 2.15.1):** `config` für alle drei
Profile exit 0 ohne stderr; `APP_KEY`/`DB_USERNAME`/`DB_PASSWORD` einzeln unset ⇒ exit 1 mit
`:?`-Meldung; **beide Image-Rollen gebaut**; `User=[www-data]`, `/proc/1/status` → `Uid: 33`;
`artisan --version` = Laravel 13.32.0, `route:list` = 109 Routen mit `APP_ENV=production`;
**kein** Service published `0.0.0.0`, mailpit nicht im prod-Profil; Build-Key via
`docker history` als **nicht persistiert** nachgewiesen.

- [ ] **WP-5-D1 (NEU, offen):** **Root-`.dockerignore` fehlt** ⇒ Build-Context ≈ 380 MB
  (`frontend/node_modules` 255 MB, `backend/vendor` 103 MB, `.git`). Das Image ist sauber,
  nur der Build langsam. **Nicht** blind anlegen: es verändert Build-Inputs, die WP-5 gerade
  validiert hat ⇒ braucht einen Build-Nachweis (`docker compose --profile prod build backend`).
- [ ] **WP-5-D2 (NEU, offen):** **Zwei redundante Env-Mechanismen** für den Dev-Pfad:
  `deployment/dev.env` + `--env-file` (WF-2) **und** die `docker-compose.dev.yml`-Override
  (WF-2 früherer Lauf) **und** die Compose-Header-Anleitung „`deployment/.env` anlegen"
  (WP-5). Harmlos, aber drei Wege für dieselbe Sache ⇒ **einen auswählen**, Rest entfernen.
  (Bevorzugt `--env-file deployment/dev.env`, weil ein `-f`-Override den Parse-Fehler der
  Basis-Datei mit `${VAR:?…}` prinzipiell **nicht** heilen kann — das steht so im WF-2-Report.)
- [ ] **WP-5-D3 (LOW, Info):** `Dockerfile.e2e` — hadolint `DL3002` für das nötige
  `USER root`-Reset braucht `# hadolint ignore=DL3002`; der Kommentar „Kompromittierung von
  CDN/Index wäre stillschweigend eingebackt worden" **überzeichnet** (Manifest kommt aus
  derselben Origin ⇒ GPG-Signaturprüfung, nicht der Digest, wäre der wirksame Schutz).
  Redundantes `COPY --from=composer:2` lässt zwei Composer-Binaries im Image (PATH bevorzugt
  die gepinnte) — bewusst o. a. Build-Order-Unabhängigkeit.
- [ ] **WP-5-D4 (INFO, Prod-Sizing):** FPM-Pool nutzt den Image-Default
  `pm.max_children = 5`; Basis-Image ≈ 965 MB, weil die Build-Dependencies in einer Stufe
  bleiben (beides **pre-existing**). Für Prod-Sizing relevant, nicht für diesen Batch.
- [ ] **WP-5-D5 (Go-Live-Hinweis, in den Go-Live-Plan übernehmen):** Der **erste** Prod-Start
  scheitert **by design**, bis `sudo mkdir -p /srv/media/accreditation && sudo chown -R 33:33`
  gesetzt ist, und Host-Port 9000 muss frei sein.

### WP-11 — Test-Hygiene: `Storage::fake`-Reste machen die Suite geschichtsabhängig (Build-Agent-Befund)
> **Nicht** der previously dokumentierte Cross-Prozess-Race. Isoliert laufen die Tests
> grün, die **wiederholte** Vollausführung im selben Checkout nicht. Reproduktion:
> `rm -rf backend/storage/framework/testing/disks/*` ⇒ `1142 passed`, ohne Wipe
> `2 failed` (`MandantMediaSelfServiceTest > logo delivery streams…`,
> `MediaMigrationTest > force migrates…`). Ursache: Die `media`-/`private`-Disk-Wurzeln
> der Test-Umgebung zeigen auf `backend/storage/framework/testing/disks/` — ein
> **geteiltes, gitignoriertes** Verzeichnis (`storage/framework/testing/.gitignore` = `*`).
> Tests schreiben dort echte Dateien (`media/verband-a.test/logo.png` + `logo.webp`,
> `private/user-media/verband/1/portrait/*.jpg`), **niemand räumt auf**, der Ordner
> akkumuliert über Läufe hinweg ⇒ Reihenfolge-/Historie-abhängige Assertions.
> Verletzt die Zero-Pre-existing-Failures-Policy und erzeugt dauerhaft Flakes.

- [ ] **WP-11-a:** Die Fake-Disk-Roots pro Test **frisch** machen (Laravel-Semantik von
  `fake` = frisch). Ansatz in `backend/tests/TestCase.php` (dort wurde für
  `Request::$trustedHostPatterns` bereits ein similar gelegter Cleanup eingebaut — Muster
  übernehmen) **oder** roots in `phpunit.xml`/Test-Config pro Lauf eindeutig machen.
  ⚠️ **Nicht** blind umsetzen: die Suite ist aktuell grün, ein aggressives per-Test-Wipe
  kann Tests brechen, die auf Resten einer anderen Klasse bauen. Deshalb: Änderung muss
  die Suite **zweimal hintereinander** grün halten **und** den obigen Repro-Fix **belegen**:
  Suite mit absichtlich erzeugten Resten starten ⇒ trotzdem grün.
- [ ] **WP-11-b:** Regressionstest/Meta-Guard: ein Test, der prüft, dass die Suite keine
  Reste in `storage/framework/testing/disks/` hinterlässt (oder dass `tearDown`/Cleanup
  greift), damit die Regression nicht wiederkehrt.
- [ ] **WP-11-c:** Befund in `backend/AGENTS.md` (Parallel-Testing-Abschnitt) **präzisieren**:
  die bestehende Notiz nennt nur den Cross-Prozess-Race; die eigentliche Ursache ist
  **Rest-/History-Abhängigkeit im selben Checkout**. Beide sauber trennen.

### Hand-offs aus WP-2 (2026-09-26, Implementer-Report)
- [ ] **WP-3-H1 (N+1, NEW):** `AllocationService::issueQrTokens()` kostet seit Token-v2
  **1 Extra-Query pro freigegebenem Antrag** (gemessen: 10 Approvals → 10
  `accreditations`-Lookups), weil das v2-Token `accreditation.mandant_id` braucht und der
  Bulk-Query die Relation nicht lädt. Fix in `AllocationService.php:309-313`:
  `->with('accreditation:id,mandant_id')` an die `whereIn('id', $ids)`-Query.
  **Eigner: WP-3** (Datei identisch, sonst Konflikt).
- [ ] **WP-5-H2 (Doku, NEW):** `APP_PREVIOUS_KEYS` in `backend/.env.example` dokumentieren
  — operationelle Voraussetzung für rotationssichere Badges (ohne sie werden bestehende
  QR-Tokens bei `APP_KEY`-Rotation invalid). **Eigner: WP-5**, aber die Datei ist
  aktuell in-flight bei WP-1 ⇒ erst nach WP-1-Land anlegen.
- [ ] **Scope-Notiz:** WP-2 hat zusätzlich `backend/app/Services/BadgeExportService.php`
  angefasst (nicht im Datei-Plan, aber für WP-2-d `lazyById(200)` + CSV-Streaming
  erforderlich). Selbst offengelegt, im Diff prüfen.

### Ergebnis-Notiz
_(wird nach Verifikation ausgefüllt — §4: abgeschlossene TODOs vollständig entfernen)_

