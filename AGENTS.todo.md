# Task Board — open-accriditation

> Stand: 2026-08-13. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
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

### P0 — Scaffold (Repo, Compose, CI, Doku) 🟢 AKTIV

- [x] `git init` (Branch `master`) + öffentliches Repo **`reisi007/open-accreditation`** angelegt (2026-08-13)
- [x] `.github/workflows/base-image.yml`: Base-Image-Build (Cron `0 1 * * *` + push master, `DB_EXT=pgsql`, ghcr.io `accriditation-base:8.5/latest`)
- [x] Referenz-Screenshots des Altsystems auf Wunsch entfernt
- [ ] Root-Struktur: `scripts/` (backend/ frontend/ deployment/ features/ vorhanden)
- [x] `backend/AGENTS.md` + `frontend/AGENTS.md` (Regeln aus Portal übernommen, Brand→Mandant)
- [x] `deployment/docker-compose.yml` **Postgres** (+ Mailpit) statt MariaDB; `deployment/Dockerfile` mit `DB_EXT=pgsql`
- [x] `backend/`: Laravel 13 Skeleton (composer.json = neueste Portal-Deps, minus Stripe/Scout/Meili/zipstream wenn ungenutzt), `phpunit.xml` SQLite `:memory:`, JWT-Config, `config/mandants.php`-Basis
- [x] `frontend/`: Vite-React-TS + Tailwind v4 + daisyUI v5 + Lingui (DE+EN) + Vitest + Playwright; `package.json` = neueste Portal-Deps (minus Stripe/Tiptap/Photoswipe/Recharts wenn ungenutzt)
- [ ] `.github/workflows/ci.yml` (backend/frontend/e2e) + README-Setup-Abschnitt (Postgres, Seed, Login)
- [x] `features/` Basis (`README.md`, `01-multi-tenancy.md`, `02-domain-model.md` Skizze)
- [x] **Verifikation P0 (2026-08-13):** Verdict **APPROVED** — Backend/Frontend/Infra/Hygiene/Architektur+Security grün. Befunde siehe unten.

### P0-Fix — Befunde + CI (Delegieren, danach Verifikation)

- [x] **B1 (LOW):** `frontend/package.json` `pnpm.overrides` → `frontend/pnpm-workspace.yaml` (pnpm ≥10), `packageManager` auf `pnpm@11.21.0`, `minimumReleaseAge: 0`; Overrides verifiziert aktiv (Lockfile: react-router 7.18.2, nanoid 3.3.17, brace-expansion 5.0.9, svgo 4.0.2, postcss 8.5.23, undici 7.29.0) — Sicherheits-Pins greifen
- [x] **B2 (LOW):** `frontend/eslint.config.js` `ignores` für `src/locales/**/*.messages.js`
- [x] `.github/workflows/ci.yml`: Jobs `backend` (PHP 8.5 + Postgres/Mailpit-Service, composer, test, pint), `frontend` (pnpm 11.21.0, lint/build/test:run), `e2e` (nur push/workflow_dispatch, Postgres, serve+dev, `@smoke`, Report-Upload) — keine Secrets
- [x] `scripts/e2e-up.sh`: idempotent (compose up → pg_isready → .env → key:generate/jwt:secret bei leer → migrate+seed), 2× e2e ausgeführt
- [x] `DatabaseSeeder` auf `firstOrCreate` (ADMIN_EMAIL/ADMIN_PASSWORD, Fallback `admin@example.com`/`admin`) + `DatabaseSeederTest` (3 Tests); `php artisan test` → 5 passed — Grund: Skeleton-Seeder war nicht idempotent
- [x] README-Setup finalisiert (scripts/e2e-up.sh, Frontend, Dev-Login, Tests, Mandanten-Konzept)
- [x] **Verifikation (2026-08-13):** Verdict **APPROVED** — F1–F4 alle `low`. **F3 (Prod-Guard für Default-Admin) → P7 (Env-Hardening) mitnehmen.**

### P1 — Multi-Tenant + Auth + Rollen 🟡

**Vorbereitet (2026-08-13), Delegation startet nach P0-Verifikation.**

**P1a — Mandant-Grundlage (Delegieren):**
- [x] Migrationen: `mandants`, `mandant_domains` (hostname unique → mandant_id). Modell `Mandant` (slug, name, logo_path, header_path, impressum/privacy, smtp_config JSON, teams_enabled, is_primary, active)
- [x] `MandantContext`-Middleware: Host-Header → Mandant auflösen (Cache), unbekannte Domain → 404; `forCurrentMandant()`-Scope-Muster (wie Portal Brand)
- [x] **Primary-Mandant-Domain = `accreditation.test`** (Dev, Herd) — Seeder + `backend/.env.example` (`APP_URL=https://accreditation.test`) + README
- [x] Tests: PHPUnit `MandantContextTest` (Host-Resolution, unbekannt, Cache), Scope-Isolation → **20 passed, 44 assertions**
- [x] **Verifikation (2026-08-13):** APPROVED. Follow-ups (alle `low`): B1 Negative-Cache unbekannter Hosts (60s-TTL) · B2 Referer-Fallback auf Vite-Origin (`localhost:5173`) einschränken · B3 Prod `trustHosts()` aus `mandant_domains` (vor P1b-Auth) · B4 Config-Kommentar „Primary gecacht" korrigieren · B5 `/up` von Mandant-Auflösung ausnehmen · B6 `email_verified_at` im Seeder (fillable/info) — B1–B5 als P2/P7-Hardening-Items.

**P1b — Auth + Rollen (Delegieren):**
- [ ] Registrierung (E-Mail-Aktivierung), Login/Logout via JWT httpOnly-Cookie (jwt-auth), Refresh, `auth('api')`
- [ ] `roles` + `role_user` (mandant-scoped): super_admin (global), mandant_admin, team_admin, user, verifier
- [ ] AuthController/UserResource-Serialisierung (kein Passwort/Token-Leak)
- [x] Tests: PHPUnit (Registrierung+Aktivierung, Login/Logout, Rollen-Zuweisung, Mandant-Isolation des Auth) → **70 passed gesamt** (mit P1c)
- [ ] **E2E ausstehend:** Playwright `@smoke` API-basiert (Registrierung + Login gegen Dev-Backend) — Frontend-Auth-UI kommt in P2

**P1c — User-Profil + Fotos (Delegieren):**
- [x] Profil-Felder: Titel, Vorname, Nachname, Geschlecht, Geburtsdatum, Straße/PLZ/Ort/Land, Unternehmen, Telefon/Fax, Branche (Print/TV/Online/Radio/Foto/Sonstige), Position, Fotoweste vorhanden/Nr
- [x] Foto-Uploads: Porträt (Empfehlung 400×600, Validierung), Presse-ID, Anhänge (MIME/exiftool-Check, auth-gated Delivery)
- [x] Tests: PHPUnit (Validierung, Upload-Pflicht, Größenregeln) — `@feature:profile`-Playwright nach Frontend-UI (P2)
- [x] **Verifikation (2026-08-13):** APPROVED. Befunde: **F1 (medium)** Aktivierungslink nutzt `config('app.url')` statt Mandanten-Domain → Cross-Mandant-Login bricht → Fix-Task · F2 (low) JWT-Parser-Kette auch Header/Query/Form → Cookie-only · F3 (low) `local`-Disk `serve=>true` teilt Root mit `private` · F4 (low) activation_token klartext → sha256 · F5 (low) Uploads ohne Kontingent/Rate-Limit · F6 (info) 403-Texte offenbaren Kontoexistenz (bewusst) · F7 (info) Mandant-Check nur beim Login (P2: Ressourcen scopen). **F2–F5 → P7-Hardening.**

**P1d — Autorisierung (Delegieren):**
- [ ] Policies/Gates: super_admin / mandant_admin / team_admin / user / verifier; Team-Admin darf Verbands-Akkreditierungen eigener Personen read-only sehen (Vorbereitung P2/P3)
- [ ] Tests: PHPUnit RolePermissionTest (Zugriffsmatrix)

### P2 — Admin: Mandant/Team/Kategorie/Event 🟡

- [ ] Super Admin: Mandanten-CRUD inkl. Domain, Logo, Header-Bild, Impressum/Datenschutz, SMTP, `teams_enabled`
- [ ] Mandant-Admin: Kategorien (Basis), Events, Benutzer, Freigaben
- [ ] Team-Admin: eigene Events + Akkreditierungen, Kategorie-Override, Heimstätte; **read-only Sicht auf Verbands-Akkreditierungen eigener Personen**
- [ ] Events: Titel/Datum/Ort(Override)/Wettbewerb/Frist(Default+Override); Ebene Mandant vs. Team
- [ ] Tests: PHPUnit (CRUD, Scopes, Override-Precedence), Playwright `@feature:admin:*`

### P3 — Öffentliches Portal + Anmeldung + Allocation-Engine 🟡

**Vorbereitet (2026-08-13), folgt nach P2.**

**P3a — Öffentliches Portal (Delegieren):**
- [ ] Landing: Mandant-/Team-Kacheln (wie Altsystem), Sprachumschalter DE/EN
- [ ] Veranstaltungskalender: Filter Verein/Typ, Event-Status aktiv/inaktiv, Akkreditierungsfrist-Countdown
- [ ] Event-Detail: Titel, Datum, Ort (Heimstätte/Override), Wettbewerb, Frist (Start/Ende + Countdown), Event-Verwalter-Kontakt
- [ ] Tests: Playwright `@feature:accreditation` (Kalender-Filter, Detail, Status-Logik)

**P3b — Selbstanmeldung (Delegieren):**
- [ ] Akkreditierungs-Antrag: Person wählt Kategorie/Scope (Event/Liga/Saison), prüft Verfügbarkeit (Quota offen?), Foto/Presse-ID/Anhänge, Status `requested`
- [ ] „Meine Akkreditierungen": Statusübersicht der eigenen Anträge
- [ ] Tests: PHPUnit (Antrag-Logik, Quota-Prüfung beim Antrag), Playwright `@feature:accreditation`

**P3c — Allocation-Engine (Delegieren, STRICT Unit-Tests):**
- [ ] Service `AllocationService`: Kernregeln — Quota (Limit), FCFS nach Fristende, Blacklist (Person + Domäne) nie freigegeben, VIP-Prio (Person/Domäne) vorgereiht, auto/manual je Akkreditierung
- [ ] Massenfreigabe: „alle freigeben" + „erste X freigeben" (Respektiert Quota + Blacklist + VIP-Reihenfolge)
- [ ] Trigger: manuell (Admin), nach Fristende (automatisch, Schedule/Queue)
- [ ] Tests: PHPUnit ausführlich — Überzeichnung (Quota erschöpft → Ablehnung), VIP-Reihenfolge (vorgereiht), Frist-Randfälle (vor/nach Deadline, exakt am Deadline-Ende), Blacklist (Person+Domäne, auch bei auto-approve), „erste X"-Zuteilung deterministisch, auto/manual-Kombinationen

**P3d — Sub-Akkreditierungen (Delegieren, STRICT Unit-Tests):**
- [ ] Modell: `sub_accreditations` (Typ Park/Sitz, eigenes Quota, eigene auto/manual-Allokation) + Anträge nur bei vorhandener Haupt-Akkreditierung
- [ ] Allokation: Haupt-Akkreditierung zuerst, dann Sub-Selektion; Überzeichnung (z. B. 75 Anfragen / 50 Plätze) → 25 Ablehnungen; VIP-Prio auf Sub-Ebene anwendbar
- [ ] Tests: PHPUnit — Haupt↔Sub-Abhängigkeit, Quota je Sub-Typ, VIP-Reihenfolge, deterministische Zuteilung

**P3e — Admin-Freigabe-Sicht (Delegieren):**
- [ ] Mandant-/Team-Admin: Antragsliste mit Status, Einzel-Freigabe/Ablehnung (mit Grund), Massenfreigabe-UI (alle / erste X), Blacklist-Verwaltung
- [ ] Tests: Playwright `@feature:accreditation`

### P4 — Ausweis (Template, PDF, CSV/Excel, QR) 🟡

- [ ] `badge_templates` (Layout-JSON: Felder/Logo/Header/Farben) + Feld-Editor-UI (MVP: Feld-Set + Positionen)
- [ ] PDF-Export (dompdf/Chromium) + CSV/Excel-Export (Serienbrief)
- [ ] QR-Code (signed Token) + öffentliche Prüfseite (Foto/Status) + Ordner-Scan-View
- [ ] Tests: PHPUnit (Token-Signatur/Verifikation, Template-Render), Playwright `@feature:badge`

### P5 — E-Mail-Workflow 🟡

- [ ] Mailables: Aktivierung, Freigabe/Ablehnung, Frist-Reminder, Pass-Versand (PDF/Wallet)
- [ ] SMTP je Mandant (settings-Overlay), Queue
- [ ] Tests: PHPUnit (Mailables, SMTP-Config-Override), Mailpit-Assertions

### P6 — Wallets (PKPASS) + Sub-Karten 🟡

- [ ] Apple Wallet (.pkpass) + Google Wallet: Generierung/Signierung, Ausgabe via E-Mail/Link
- [ ] Park-/Sitzkarten als eigene Ausweis-Typen mit eigener Vorlage
- [ ] Tests: PHPUnit (Pass-Generierung/Validierung), Playwright `@feature:wallet`

### P7 — Polish + Deploy 🟡

- [ ] Caddy/Reverse-Proxy-Konfig (multi-Domain), Env-Hardening (APP_KEY/JWT_SECRET-Guards)
- [ ] Vollsuite grün (PHPUnit + Vitest + Playwright), `@smoke` nach jedem Schritt

---

## 🚨 CI-Analyse 2026-08-13 (Befunde, Fix delegiert nach P1b+P1c)

**Rot:** P1a-Run `31730486061` (E2E-Job) · 2× Dependabot-nanoid-PR · 1× Base-Image beim Initial-Commit (Dockerfile fehlte — seitdem grün).

- [x] **C1 (E2E-Fail, hoch):** Fix umgesetzt — `/up`-Short-Circuit in Middleware, Loopback-Fallback (`localhost`/`127.0.0.1`/`::1`) in local/testing → Primary; Health-Check `/up`; `artisan serve --no-reload`. 8 neue Middleware-Tests.
- [x] **C2 (Dependabot, mittel):** `.github/dependabot.yml` — composer täglich gebündelt, npm wöchentlich mit `ignore` für pnpm-Overrides (nanoid, react-router(-dom), brace-expansion, svgo, postcss, undici).
- [x] **C3 (minor):** Postgres-Service aus Backend-Job entfernt; Mailpit begründet behalten.

## 🔧 Workflow (delegieren + verifizieren)- Build-Agent: nur diese Datei + `AGENTS.md` (+ referenzierte Doku). Kein Produktiv-Code.
- Jeder TODO-Block → ein Implementer-Subagent (isoliert, präzise Anweisungen + Ziel-Dateien).
- Jede Umsetzung → ein **separater** Verifikator-Subagent (Tests/Lint/Build, Diff-Review **+ Architektur- und Security-Review** nach `AGENTS.md` §5; `critical`/`high` blockieren APPROVED).
- Visuelle Checks (Template-Editor, Ausweis-Layout, Screenshot-Abgleiche) → `vision`-Subagent.

## 📌 Offene Punkte / Risiken

- [x] **Dependabot #1 (high, nanoid frontend):** Override auf `3.3.18` (CVE-2026-67213 gepatcht; 5.x wegen postcss-CJS inkompatibel) — verifiziert, Lockfile ohne residuale verwundbare Version
- [ ] Projektname/Repo-URL festlegen (Verzeichnis heißt `open-accriditation`, Tippfehler)
- [ ] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel aus `AGENTS.md` §2 durchsetzen
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4)
- [ ] Google-Wallet: API-Zugang/Issuer-Setup erforderlich (externer Schritt, P6)
