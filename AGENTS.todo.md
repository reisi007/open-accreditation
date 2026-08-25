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

- [ ] **P3e-B3:** `escapeLike()`-Duplikate (4 Controller) konsolidieren (Refactoring-Kandidat)
- [ ] **P3e-B4:** dedizierter Filter-Endpoint statt N paralleler Requests (`fetchAllAdminSubAccreditations`)
- [ ] **P3e-B5:** `cache:clear`-Hinweis in `e2e-up.sh`/CI-Doku dokumentieren
- [ ] **P3b-F2:** `features/02-domain-model.md` auf `created_at` präzisieren
- [ ] **P2b-F5:** `is_team_override`-Semantik dokumentieren
- [ ] **P1c:** `@feature:profile`-Playwright-E2E
- [ ] **P4-F4:** Layout-Schema um `qr`-Feld erweitern (Fixposition vs. Template-Überlappung)
- [ ] **Google-Wallet-Issuer-Setup** (extern, P6-B2) — falls Wallet ab Tag 1 aktiv sein soll, in Phase B vorziehen
- [ ] **P5-F4:** Queue-Integration für Mails (aktuell synkron als MVP-Entscheidung)
- [ ] **BE-R8:** Doku zur Bulk-Reanimations-Limitation in `features/` ergänzen
- [ ] **Vite-Proxy/MandantContext:** Backend akzeptiert `*.localhost:5173`-Referer (Dev-QoL)
- [ ] **Logo-E-Mail-Varianten** (`logo-email-64/128.png`): Workflow für E-Mail-Embeds (reserviert in SOLL-Doku)

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

- [ ] **P3e-B3 (info)** Controller-Scope-/`EscapeLike`-Duplikation (4 Admin-Controller) → Refactoring-Kandidat (P7).
- [ ] **P3e-B4 (info)** `fetchAllAdminSubAccreditations` macht N parallele Requests → dedizierter Filter-Endpoint (später).
- [ ] **P3e-B5 (info)** E2E-Rate-Limiter-State: 7-Tage-TTL im DB-Cache → Login-Throttle-429 bei Back-to-Back-E2E/Screenshot-Läufen: `php artisan cache:clear` vor E2E-Läufen in `e2e-up.sh`/CI-Doku fest dokumentieren (Determinismus).
- [ ] **P3b-F2 (info)** `applied_at` vs `created_at`: API exponiert `created_at`; `features/02-domain-model.md` ggf. auf `created_at` präzisieren → P3e-Cleanup.
- [ ] **P2b-F5 (info)** `is_team_override` = `team_id !== null` (Semantik-Kosmetik: Badge auf jeder Team-Kategorie) → akzeptiert/dokumentieren.
- [ ] **P2c-F4 (info)** super_admin nähert „aktuellen Mandant" als Primär-Mandant an (Dev ok; Nicht-Primär-Domain zeigt falsche Teams) → Multi-Domain-Admin-UX in P3/P7.
- [ ] **P1c (info)** `@feature:profile`-Playwright-E2E folgt nach Frontend-UI (P2).

- [ ] **P4-F4 (info)** QR-Fixposition (20 mm unten rechts) kann Template-Felder überlappen → Layout-Schema um `qr`-Feld erweitern (später).
- [ ] **P5-F3 (info)** Reminder-Dedup ist pro Tag (bis 4 Mails im 3-Tage-Fenster) — bewusste MVP-Entscheidung (dokumentiert in `SendReminders.php`).
- [ ] **P5-F4 (info)** Queue-Integration (synchroner Versand als MVP-Entscheidung) → später/Post-MVP.
- [ ] **P6-B2 (info)** ohne `GOOGLE_ISSUER_ID` leeres id-Präfix im Preview-Modus → dokumentiert, kein Risiko.

- [ ] **Vite-Proxy / MandantContextMiddleware (info)** `*.localhost:5173`-Hosts in lokalem Dev unerreichbar (Vite `changeOrigin` rewritet Host → Primary-Mandant); Screenshot-Harness umgeht das mit `emptyMock`-Stubs → Zukunft: Backend akzeptiert `*.localhost:5173`-Referer (dokumentierte Verbesserung).

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

- [x] Repo-Tippfehler `open-accriditation` → `open-accreditation` bereinigt (Verzeichnis war bereits korrekt; String-Refs in package.json/.env/READMEs/e2e-up.sh/AGENTS-Docs + stale Blade-Views gecleared; opencode-DB + Session zeigten bereits korrekten Pfad). GitHub-Remote `reisi007/open-accreditation` bestätigt (existiert, korrekt benannt, Work gepusht) — Repo-URL final.
- [ ] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel aus `AGENTS.md` §2 durchsetzen
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4)
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
- [ ] **P1c-F1 (low)** — `profile.spec.ts:53`: `createActivatedSession` liefert `email` zurück, ungenutzt (Design-Nit, belassen).
- [ ] **P1c-F2 (low)** — `profile.spec.ts:88`: Assertion auf exakten String `Profil aktualisiert.` koppelt E2E an Backend-Copy; bei Copy-Änderung anpassen.
- [ ] **P3e-B4-F1 (low)** — `SubAccreditationController.php:256`: `assertAccreditationFilter()` dupliziert `AdminApplicationController.php:212` (Mirror); langfristig in `ResolvesAdminTeamScope`-Concern.
- [ ] **P3e-B4-F2 (low)** — `ApprovalsPage.tsx:872`: statischer SWR-Key statt alter `accreditations.length`-Abhängigkeit (nur Dropdown-Optionen, harmlos).
- [ ] **P3e-B4-F3 (low)** — `SubAccreditationController.php:132`: globales `orderBy('id')` statt Gruppierung nach Akkreditierung → evtl. andere Dropdown-Reihenfolge.
- [ ] **P2-F1 (low)** — `UserController.php:57`: Non-ASCII-Divergenz — SQLite `LOWER()` faltet nur ASCII (Suche `müller`≠`MÜLLER` im Test-Engine, auf PG ok). Trade-off der Portabilitätsregel; ggf. als akzeptiertes Risiko in `features/` dokumentieren.
- [ ] **P2-F2 (low)** — `UserController.php:57`: `LOWER(col)` verhindert b-tree-Index-Nutzung auf PG; bei wachsenden User-Beständen funktionalen Index `LOWER(name)` ergänzen (portabel). Nicht blockierend.

### Bewusst NICHT in diesem Batch (braucht User / externe / Go-Live-Infra)
- P7 Go-Live (User-Freigabe) · finale User-Abnahme · BE-R1 (E-Mail-Unique-Scope, User-Entscheidung)
- Feld-Editor-Umfang (P4, User-Klärung) · Google-Wallet-Issuer (extern)
- P5-F4 Queue-Integration (braucht Queue-Worker → Go-Live-Infra, Post-MVP belassen) · P5-F3/P6-B2 (bereits dokumentierte MVP-Entscheidungen)

### Wave-Plan (erweitert, max. 2 parallel, disjunkte Ziel-Dateien)
- **Wave 1 (parallel, 2):** Subagent A (Backend: escapeLike + Vite-Proxy) ∥ Subagent B (Docs-Batch) — **DONE + B verifiziert (APPROVED)**
- **Laufend:** A-Verifikator (Backend) ∥ Subagent D (Frontend: P1c E2E)
- **Wave 2 (parallel, 2):** Subagent C (Backend: P3e-B4 Filter-Endpoint) ∥ Subagent E (Docs: P2c-F4)
- **Wave 3 (parallel, 2):** Subagent F (Backend: Portabilitäts-Audit) ∥ Verifikator C ∥ Verifikator D (sofern Kapazität; sonst sequenziell)
- **Verifikation:** je Umsetzung separater Verifikator-Subagent (§5: Architektur + Security + Tests/Lint/Build)

---

## 🛠️ Session 2026-08-25b — User-Entscheidungen (interaktiv geklärt) + Umsetzung

> Entscheidungen vom Benutzer: **BE-R1 = Per-Mandant `email`-unique** · **Google-Wallet jetzt einleiten** ·
> **Feld-Editor = voll frei positionierbar** · **Go-Live weiterhin geparkt**.

### TODO-Liste (actionable, mit Test-Forderung)
- [ ] **BE-R1 (MEDIUM, Entscheidung: per Mandant unique)** — Registrierung/Login auf `(mandant_id, email)` umstellen:
  Migration (global `users.email`-Unique → composite `unique(mandant_id, email)`, portable), `AuthController::register`
  Validierung per Mandant scopen, Login/E-Mail-Lookup über den Host-Mandant auflösen. → PHPUnit:
  gleiche E-Mail auf 2 Mandanten ok, Duplikat im selben Mandant 422, Login host-gescoped, Cross-Mandant-Leak ausgeschlossen.
  [Subagent G]
- [ ] **Google-Wallet-Issuer-Setup vorbereiten** — Schritt-für-Schritt-Issuer-Setup-Checkliste in `features/wallet-pkpass.md`
  ergänzen (externe Owner-Aktion: Issuer-Account anlegen, `GOOGLE_ISSUER_ID` setzen), Config-Keys/Preview-Fallback dokumentieren.
  [Subagent H / Docs]
- [ ] **Feld-Editor SOLL-Spec (P4, Entscheidung: voll frei positionierbar)** — `features/`-Spec für Drag&Drop-Feld-Editor
  (X/Y-Positionen im Template-Schema inkl. `qr`-Feld aus P4-F4, Feldtypen, Validierung, PDF-Render-Kontrakt). Erst Spec,
  danach Umsetzung in Folgewellen. [Subagent H / Docs]

### Feld-Editor Umsetzung — Etappenplan (je Etappe: Implementer ∥ nichts, separater Verifikator, UI-Review-Skill)
> **UI-Test-Regel (STRICT) für jede Etappe:** Nach Umsetzung (a) funktionale Tests (PHPUnit/Vitest/Playwright
> getaggt `@feature:badge-editor`), dann (b) **UI-Review-Skill**: `cd frontend && pnpm test:screenshots`
> (nur betroffene Routen, manifest-getrieben) → PNGs an `vision`-Subagent in Batches ≤ 10 (erst filled, dann empty;
> desktop vor mobile) → Findings-Report (critical/high blockieren APPROVED) → Fixes → Re-Capture betroffener Routen +
> Vision old-vs-new-Diff. Erst wenn Vision-Loop sauber: nächste Etappe.

### PDF-visuelle-Verifikation (Überlegungen, 2026-08-26)
> Ziel: generierte Badge-/Ausweis-PDFs genauso visuell verifizieren wie UI-Screenshots (Vision-Agent gegen Checklist:
> QR-Position, Feld-Überlappung, Abschneiden, Kontrast, Skalierung). Besonders relevant für **FE1** (Render-Kontrakt).

- **Tool-Befund lokal (macOS):**
  - `magick`/`convert` (ImageMagick) **installiert**, aber PDF-Rendering **scheitert**: ImageMagicks PDF-Delegate ist
    Ghostscript (`gs`) → `gs` fehlt (`brew install ghostscript` würde `magick` PDF-fähig machen, mit `-r 150..300`
    DPI-Kontrolle + Multi-Page).
  - `pdftoppm` (poppler) nicht installiert — wäre die hochwertigste Option (`brew install poppler`; im CI-Image via
    apt `poppler-utils`).
  - ✅ **`sips` (macOS-Bordmittel) funktioniert out-of-the-box**: dompdf-Test-PDF (A6 landscape) → sauberes PNG
    (420×298 @72dpi). Für einseitige Badge-PDFs ausreichend; erste Seite only.
- **Fixture-Pfad:** Badge-PDF wird backend-seitig erzeugt (dompdf ^3.1 verifiziert): entweder über den auth-geschützten
  Badge-Endpoint im laufenden Dev-Stack oder per PHPUnit/Artisan-Fixture in eine temp Datei gerendert → `sips` → PNG.
- **SOLL-Pipeline (FE1-Verifikation + künftige PDF-Änderungen):**
  1. Badge-PDF generieren (Dev-Stack/Fixture), 2. `sips -s format png x.pdf --out x.png` (lokal) bzw. `magick -density 200`
     nach Ghostscript-Installation, 3. PNG(s) an `vision`-Subagent mit PDF-Checkliste (QR unten rechts 20×20 mm,
     Felder ohne Überlappung/Abschneidung, Font-Skalierung, Rückwärtskompatibles Default-Layout),
  4. Findings-Report wie beim UI-Review (critical/high blockieren APPROVED), 5. bei Fixes: neu rendern + old-vs-new-Diff.
- **CI (optional, Follow-up):** für automatisierte PDF-Vision-Checks im E2E-Job `poppler-utils` (pdftoppm) ins
  `deployment/Dockerfile.e2e` aufnehmen — nicht blockierend für FE1, lokale Verifikation genügt zunächst.

- [ ] **FE1 — Template-Schema + Backend-Render-Kontrakt:** Felder mit `x`/`y`/`width`/`height`/`fontSize`/`alignment`
  (mm-basiert) im Template-Schema inkl. `qr`-Fixfeld (P4-F4); API-Validierung (Bounds, Mindestgröße); PDF-Render nutzt
  Koordinaten (`BadgeRenderService`); Rückwärtskompatibilität (Default-Layout wenn keine Koordinaten).
  Tests: PHPUnit (Schema/API/PDF-Regression) + Vitest (Schema-Utils). [Implementer I]
- [ ] **FE2 — Editor-Basis-UI:** Template-Editor-Seite mit Ausweis-Vorschau (DIN-Format, mm-skaliert), Feldliste,
  Auswahl + Eigenschaften-Panel (X/Y/Breite/Höhe/Font/Alignment als Zahleneingaben), Persistenz-Roundtrip.
  Tests: Vitest + Playwright `@feature:badge-editor` + UI-Review (Vorschau-Seite, filled/empty, Desktop+Mobile). [Implementer J]
- [ ] **FE3 — Drag&Drop:** Freies Ziehen der Felder auf der Vorschau mit Grid/Snap, Bounds-Clamping, Überlappungs-Warnung;
  Positionen synchronisieren Panel ↔ Vorschau ↔ Speicherung. Tests: Playwright `@feature:badge-editor` (+`@regression`)
  + UI-Review mit Vision old-vs-new-Diff der Editor-Routen. [Implementer K]
- [ ] **FE4 — Polish + Full-Regression:** Resize-Griffe, Alignment-Hilfslinien, Tastatur-Nudging (Pfeiltasten), i18n DE/EN,
  leere/zustandsreiche Templates; abschließender **kompletter** UI-Review-Lauf (alle betroffenen Routen + `@smoke`/
  `@regression` E2E) + Findings-Report + Fix-Loop bis 0 critical/high. [Implementer L]

### Bewusst NICHT in diesem Batch
- P7 Go-Live (weiterhin auf User-Freigabe) · finale User-Abnahme
- P5-F4 Queue-Integration (Go-Live-Infra, Post-MVP) · Feld-Editor-Umsetzung erst nach SOLL-Spec-Verifikation
