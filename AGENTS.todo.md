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
> abgeschlossen (verifiziert, APPROVED). P7 (Caddy multi-Domain, Prod-Deploy) wird erst nach expliziter
> Freigabe des Benutzers umgesetzt — dieser Block wartet auf die Benutzer-Freigabe.

- [ ] Caddy/Reverse-Proxy-Konfig (multi-Domain) + Prod-Deploy (Go-Live)

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

- [ ] **F7 (info)** Mandant-Check nur beim Login — P2/P3: Ressourcen (Teams, Kategorien, Events, Akkreditierungen) pro Request über `forCurrentMandant()`-Scopes scopen.
- [ ] **P3a-F1 (info)** Countdown-Plural-Workaround in `DeadlineCountdown.tsx` (String-Interpolation statt Lingui-ICU-Plural) → akzeptiert.
- [ ] **P3a-F2 (info)** E2E-Daten-Ansammlung: `ensurePrimaryMandantActivePortalEvent` erzeugt Events ohne Cleanup → lokale Kosmetik, akzeptiert.
- [ ] **P3c-F4 (info)** Test-Coverage-Nuancen (Blacklist+VIP-Kombi, case-insensitiv, approveAll mit bestehenden approved, `mode` fehlt/non-int limit, Exakt-Fit Quota) → optional nachziehen (P3e-Paket oder später).
- [ ] **P3e-B3 (info)** Controller-Scope-/`EscapeLike`-Duplikation (4 Admin-Controller) → Refactoring-Kandidat (P7).
- [ ] **P3e-B4 (info)** `fetchAllAdminSubAccreditations` macht N parallele Requests → dedizierter Filter-Endpoint (später).
- [ ] **P3e-B5 (info)** E2E-Rate-Limiter-State: 7-Tage-TTL im DB-Cache → Login-Throttle-429 bei Back-to-Back-E2E/Screenshot-Läufen: `php artisan cache:clear` vor E2E-Läufen in `e2e-up.sh`/CI-Doku fest dokumentieren (Determinismus).
- [ ] **P3b-F2 (info)** `applied_at` vs `created_at`: API exponiert `created_at`; `features/02-domain-model.md` ggf. auf `created_at` präzisieren → P3e-Cleanup.
- [ ] **P2b-F5 (info)** `is_team_override` = `team_id !== null` (Semantik-Kosmetik: Badge auf jeder Team-Kategorie) → akzeptiert/dokumentieren.
- [ ] **P2c-F4 (info)** super_admin nähert „aktuellen Mandant" als Primär-Mandant an (Dev ok; Nicht-Primär-Domain zeigt falsche Teams) → Multi-Domain-Admin-UX in P3/P7.
- [ ] **B3 (info)** Prod: `trustHosts()` aus `mandant_domains` befüllen → P7.
- [ ] **P1c (info)** `@feature:profile`-Playwright-E2E folgt nach Frontend-UI (P2).

- [ ] **P4-F4 (info)** QR-Fixposition (20 mm unten rechts) kann Template-Felder überlappen → Layout-Schema um `qr`-Feld erweitern (später).
- [ ] **P4-F5 (info)** `features/`-SOLL-Doku: P6-Wallet-Vertrag (PKPASS) + Badges/QR-SOLL fehlen → Doku-Batch (P7-Vorbereitung).
- [ ] **P5-F3 (info)** Reminder-Dedup ist pro Tag (bis 4 Mails im 3-Tage-Fenster) — bewusste MVP-Entscheidung (dokumentiert in `SendReminders.php`).
- [ ] **P5-F4 (info)** Queue-Integration (synchroner Versand als MVP-Entscheidung) → später/Post-MVP.
- [ ] **P6-B1 (info)** `relevantDate`-Semantik: nutzt `deadline_end` statt Event-Datum → mit Benutzer abstimmen (P7/Produkt).
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
> einmal verworfen → jetzt auf Branch `chore/review-todo` geschützt.

### Gesundheit
- Backend `php artisan test`: Review-Start **678 grün**. **AKTUELL ~64 rot (transient)** — BE-R2
  (globaler Tenant-Scope) ist mid-Refactor; `Event.php` fehlt `use App\Support\MandantContext;`
  → `Class "App\Models\MandantContext" not found`. Wird von BE-R2 behoben (sonst Cleanup).
- Frontend `lint:fix`/`build`/124 vitest: grün (Review-Start). FE-R1 (Pluralisierung) noch offen.

### Findings (neu) — Status
**Backend**
- [ ] **BE-R1 · MEDIUM · USER-DECISION** — `AuthController::register` globale `email`-Unique vs Per-Mandant (`AuthController.php:39`). Entscheidung ausstehend.
- [ ] **BE-R2 · MEDIUM · IN FLIGHT (be-r2)** — globaler `forCurrentMandant()`-Safety-Net + Route-Binding-Resolver (`Support/MandantContext.php`, `Models/*`).
- [x] **BE-R3 · LOW · DONE (be-r3, isoliert)** — `updateRoles` Mandant-Mitgliedschafts-Guard + 2 Tests (680 tests grün).
- [x] **BE-R4 · LOW · DONE (be-r4)** — UserController-Suche `escapeLike()` — Branch ⚠️ kontaminiert (trägt CC-R1-Controller), im Cleanup splitten.
- [x] **BE-R5 · LOW · DONE (be-r5, sauber)** — apply-Rate-Limiter `user('api')` (AppServiceProvider.php:71).
- [ ] **BE-R6 · LOW/INFO · PENDING (Batch 2)** — JWT-Cookie `SameSite=Lax` (Controller.php:28).
- [ ] **BE-R7 · INFO/LOW · PENDING (Batch 2)** — negativer Host-Cache bei Domain-Anlage (MandantContext.php:154).
- [ ] **BE-R8 · INFO · DOKUMENTIEREN** — VIP/denied nicht durch Bulk-Run reanimierbar (design limitation).

**Frontend**
- [ ] **FE-R1 · MEDIUM · REDO NÖTIG (fe-r1 leer, Datei unverändert)** — Pluralisierung `accreditationLabels.ts:31,48` → ICU (`i18n._(t\`{available, plural, …}\`)`) + DE/EN-Kataloge.
- [ ] **FE-R2 · LOW · PENDING (Batch 2 = P3a-F1)** — Interpolation `DeadlineCountdown.tsx:65,75`, `UsersPage.tsx:120` → ICU.
- [ ] **FE-R3 · INFO · OK** — `VerifyPage.tsx:71` img-src, kein JS-Risiko.

**Cross-Cutting / Infra**
- [x] **CC-R1 · MEDIUM · DONE (Inhalt in be-r4; cc-r1 leer)** — LIKE → `LOWER()` in `AdminApplicationController:85-86`, `PortalController:81`, `BlacklistController:51`.
- [x] **CC-R2 · MEDIUM · DONE (cc-r2, sauber, validiert)** — DB-Credentials → env/secret (`docker-compose.yml`, `ci.yml`). Owner: `E2E_POSTGRES_PASSWORD`-Secret konfigurieren.
- [ ] **CC-R3 · LOW · PENDING** — DB-Port `5432:5432` Host-Exposure (compose).
- [ ] **CC-R4 · LOW/INFO · PENDING** — Floating Image-Tags / mutable GH-Action-Pins.
- [ ] **CC-R5 · LOW/INFO · PENDING** — `.env.example` `APP_DEBUG=true`, kein Prod-Manifest.

### Re-Assessment der „akzeptiert/info"-Follow-ups (Subagent, read-only)
- **FIX (3):** `P3a-F1` (→ FE-R2), `P3c-F4` (Allocation-**Test**-Lücken: VIP+Blacklist-Precedence, case-insensitive, approveAll+mind.-approved, exact-fit quota), `P4-F5` (`features/`-SOLL-Docs Badge/QR/PDF + Wallet/PKPASS).
- **USER-DECISION (1):** `P6-B1` (`relevantDate` Event-Datum vs `deadline_end`, WalletPassService.php:549,562).
- **LEAVE (15), davon STALE/zu schließen (4):** `F7`, `P3e-B5`, `B3`, `P3a-F2` (bereits implementiert/mitigiert).
  Rest defensibel: `P3e-B3`, `P3e-B4`, `P3b-F2`, `P2b-F5`, `P2c-F4`, `P1c`, `P4-F4`, `P5-F3`, `P5-F4`, `P6-B2`, `Vite-Proxy`.

### Branch-Status (git, nach Churn)
- ✅ sauber: `fix/be-r5`, `fix/cc-r2`, `fix/be-r3` (isoliert)
- ⚠️ kontaminiert: `fix/be-r4` (trägt CC-R1-Controller) → Cleanup splittet
- ⚠️ leer: `fix/cc-r1` (Inhalt in be-r4), `fix/fe-r1` (FE-R1 nicht persistiert → REDO)
- ⏳ IN FLIGHT: `fix/be-r2` (Model-Refactor, transient rot)

### Verification
- Implementer liefen Tests selbst (green in Scope), ABER: Branches kontaminiert + Suite transient rot
  → **separater Verifikator pro Fix (AGENTS.md §5) STEHT AUS.** Cleanup: Branch-Reconstruction
  (nur Ziel-Dateien von Base) + per-Branch-Tests + Architektur/Security-Review, dann Merge.

### Offene Entscheidungen / Owner-Action
- **P6-B1** USER-DECISION: `relevantDate` Event-Datum vs `deadline_end`.
- **CC-R2**: GitHub-Secret `E2E_POSTGRES_PASSWORD` konfigurieren (aktuell Fallback `accriditation`).

### Nächste Schritte (Cleanup, nach BE-R2)
1. BE-R2 fertig → ggf. `Event.php` `use App\Support\MandantContext;` fixen (Subagent).
2. **FE-R1 REDO** (frischer Subagent: explizite Pfade, KEIN `git add -A`, nur Frontend, andere uncommitted Changes preserven).
3. Jeden `fix/<id>` sauber von Base rekonstruieren; `UserController.php` = BE-R4+BE-R3 mergen.
4. Per Branch `php artisan test` / `pnpm` + separater Verifikator (§5).
5. Merge → master; `AGENTS.todo.md` bereinigen (erledigte TODOs entfernen, Befunde → `features/`/`Security Risk Register`).
