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

---

## 📋 Phasen & offene TODOs

### P0 — Scaffold (Repo, Compose, CI, Doku) 🟢 AKTIV

- [x] `git init` (Branch `master`) + öffentliches Repo **`reisi007/open-accreditation`** angelegt (2026-08-13)
- [x] `.github/workflows/base-image.yml`: Base-Image-Build (Cron `0 1 * * *` + push master, `DB_EXT=pgsql`, ghcr.io `accriditation-base:8.5/latest`)
- [x] Referenz-Screenshots des Altsystems auf Wunsch entfernt
- [ ] Root-Struktur: `backend/`, `frontend/`, `deployment/`, `features/`, `scripts/`
- [ ] `backend/AGENTS.md` + `frontend/AGENTS.md` (Regeln aus Portal übernommen, Brand→Mandant)
- [ ] `deployment/docker-compose.yml` **Postgres** (+ Mailpit) statt MariaDB; `deployment/Dockerfile` mit `DB_EXT=pgsql`
- [ ] `backend/`: Laravel 13 Skeleton (composer.json = neueste Portal-Deps, minus Stripe/Scout/Meili/zipstream wenn ungenutzt), `phpunit.xml` SQLite `:memory:`, JWT-Config, `config/mandants.php`-Basis
- [ ] `frontend/`: Vite-React-TS + Tailwind v4 + daisyUI v5 + Lingui (DE+EN) + Vitest + Playwright; `package.json` = neueste Portal-Deps (minus Stripe/Tiptap/Photoswipe/Recharts wenn ungenutzt)
- [ ] `.github/workflows/ci.yml` (backend/frontend/e2e) + `README.md` (Setup: Postgres, Seed, Login)
- [ ] `features/` Basis (`README.md`, `01-multi-tenancy.md`, `02-domain-model.md` Skizze)
- [ ] Verifikation: `composer install` + `php artisan test` (Skeleton), `pnpm install && pnpm lint:fix && pnpm build`, Compose `up -d` Postgres-Healthcheck

### P1 — Multi-Tenant + Auth + Rollen 🟡

- [ ] `mandants` + `domains` Migration/Modell, `MandantContext`-Middleware (Host-Header → Mandant, Forbidden bei unbekannt)
- [ ] Auth: JWT httpOnly-Cookie, Registrierung (E-Mail-Aktivierung), Login/Logout, 5 Rollen (roles + role_user, mandant-scoped)
- [ ] User-Profil (Titel, Name, Geschlecht, Geburtsdatum, Adresse, Land, Unternehmen, Telefon/Fax, Branche, Position, Fotoweste) + Foto-Uploads (Porträt, Presse-ID, Anhänge) mit Validierung
- [ ] Authorization: Policies/Gates für super_admin/mandant_admin/team_admin/user/verifier
- [ ] Tests: PHPUnit (Kontext-Isolation, Auth-Flows, Rollen), Vitest (Auth-Hooks), Playwright `@smoke` (Login/Registrierung)

### P2 — Admin: Mandant/Team/Kategorie/Event 🟡

- [ ] Super Admin: Mandanten-CRUD inkl. Domain, Logo, Header-Bild, Impressum/Datenschutz, SMTP, `teams_enabled`
- [ ] Mandant-Admin: Kategorien (Basis), Events, Benutzer, Freigaben
- [ ] Team-Admin: eigene Events + Akkreditierungen, Kategorie-Override, Heimstätte; **read-only Sicht auf Verbands-Akkreditierungen eigener Personen**
- [ ] Events: Titel/Datum/Ort(Override)/Wettbewerb/Frist(Default+Override); Ebene Mandant vs. Team
- [ ] Tests: PHPUnit (CRUD, Scopes, Override-Precedence), Playwright `@feature:admin:*`

### P3 — Öffentliches Portal + Anmeldung + Allocation-Engine 🟡

- [ ] Public: Landing (Mandant/Team-Kacheln), Veranstaltungskalender (Filter Verein/Typ, Status aktiv/inaktiv), Event-Detail (Frist, Ort, Kontakt)
- [ ] Selbstanmeldung: Akkreditierungs-Antrag (Kategorie/Scope) mit Foto/Anhängen, Status requested
- [ ] **Allocation-Engine** (Service, STRICT unit-getestet): Quota, FCFS nach Fristende, Blacklist (Person+Domäne), VIP-Prio, Massenfreigabe (alle / erste X), auto/manuell
- [ ] Sub-Akkreditierungen: Park/Sitz nur bei Haupt-Akkreditierung, eigenes Kontingent, Überzeichnung → Ablehnung
- [ ] Tests: PHPUnit (Engine: Überzeichnung, VIP-Reihenfolge, Frist-Randfälle, Blacklist), Playwright `@feature:accreditation`

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

## 🔧 Workflow (delegieren + verifizieren)

- Build-Agent: nur diese Datei + `AGENTS.md` (+ referenzierte Doku). Kein Produktiv-Code.
- Jeder TODO-Block → ein Implementer-Subagent (isoliert, präzise Anweisungen + Ziel-Dateien).
- Jede Umsetzung → ein **separater** Verifikator-Subagent (Tests/Lint/Build, Diff-Review).
- Visuelle Checks (Template-Editor, Ausweis-Layout, Screenshot-Abgleiche) → `vision`-Subagent.

## 📌 Offene Punkte / Risiken

- [ ] Projektname/Repo-URL festlegen (Verzeichnis heißt `open-accriditation`, Tippfehler)
- [ ] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel aus `AGENTS.md` §2 durchsetzen
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4)
- [ ] Google-Wallet: API-Zugang/Issuer-Setup erforderlich (externer Schritt, P6)
