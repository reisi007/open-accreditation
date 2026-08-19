# Task Board — open-accriditation

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
- [ ] **Commit 1** (Image + Doku, ohne ci.yml) pushen → Image-Build in GH Actions abwarten
- [ ] **Commit 2** (ci.yml) pushen → CI grün abwarten (E2E-Job im Container)
- [ ] Speedup-Messung (Runner-Download vs. Image-Pull) dokumentieren

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
- [ ] **P4-F2 (low)** `AdminApplicationResource` macht Write-on-Read (lazy qr_token-Backfill während Serialisierung) → besser im Approval-Flow oder explizit (P7).
- [ ] **P4-F3 (low)** Verify nutzt `throttle:public` (geteilter Bucket mit Portal/Akkreditierungen) → eigener benannter Limiter (P7).
- [ ] **P4-F4 (info)** QR-Fixposition (20 mm unten rechts) kann Template-Felder überlappen → Layout-Schema um `qr`-Feld erweitern (später).
- [ ] **P4-F5 (info)** `features/`-SOLL-Doku: P6-Wallet-Vertrag (PKPASS) + Badges/QR-SOLL fehlen → Doku-Batch (P7-Vorbereitung).
- [ ] **P5-F3 (info)** Reminder-Dedup ist pro Tag (bis 4 Mails im 3-Tage-Fenster) — bewusste MVP-Entscheidung (dokumentiert in `SendReminders.php`).
- [ ] **P5-F4 (info)** Queue-Integration (synchroner Versand als MVP-Entscheidung) → später/Post-MVP.
- [ ] **P6-B1 (info)** `relevantDate`-Semantik: nutzt `deadline_end` statt Event-Datum → mit Benutzer abstimmen (P7/Produkt).
- [ ] **P6-B2 (info)** ohne `GOOGLE_ISSUER_ID` leeres id-Präfix im Preview-Modus → dokumentiert, kein Risiko.
- [ ] **E2E-Test-Hygiene (low)** Daten-Ansammlung: `frontend/tests/e2e/badge.spec.ts:51` hinterlässt pro Lauf `E2E Ausweis*`-Templates (Strict-Mode-Verletzung beim nächsten Lauf); ebenso akkumulieren Mandanten (>20 bricht Pagination-basierte Smoke-Assertions) → Cleanup/Teardown im E2E-Harness ergänzen.
- [ ] **Vite-Proxy / MandantContextMiddleware (info)** `*.localhost:5173`-Hosts in lokalem Dev unerreichbar (Vite `changeOrigin` rewritet Host → Primary-Mandant); Screenshot-Harness umgeht das mit `emptyMock`-Stubs → Zukunft: Backend akzeptiert `*.localhost:5173`-Referer (dokumentierte Verbesserung).

---

## 🔧 Workflow (delegieren + verifizieren)- Build-Agent: nur diese Datei + `AGENTS.md` (+ referenzierte Doku). Kein Produktiv-Code.
- Jeder TODO-Block → ein Implementer-Subagent (isoliert, präzise Anweisungen + Ziel-Dateien).
- Jede Umsetzung → ein **separater** Verifikator-Subagent (Tests/Lint/Build, Diff-Review **+ Architektur- und Security-Review** nach `AGENTS.md` §5; `critical`/`high` blockieren APPROVED).
- Visuelle Checks (Template-Editor, Ausweis-Layout, Screenshot-Abgleiche) → `vision`-Subagent.

## 📌 Offene Punkte / Risiken

- [ ] Projektname/Repo-URL festlegen (Verzeichnis heißt `open-accriditation`, Tippfehler)
- [ ] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel aus `AGENTS.md` §2 durchsetzen
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4)
- [ ] Google-Wallet: API-Zugang/Issuer-Setup erforderlich (externer Schritt, P6)
