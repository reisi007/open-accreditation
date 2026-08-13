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

### P2 — Admin: Mandant/Team/Kategorie/Event 🟡

**P2a — Super Admin: Mandanten + Teams (Delegieren, nach P1d):**
- [ ] Models: `Mandant` erweitern (logo, header_image, imprint, privacy_policy, smtp_*, teams_enabled), `Team` (mandant_id, slug unique, name, home_venue), `MandantDomain`-CRUD (hostname unique, Re-Registration validieren)
- [ ] API: `/api/admin/mandants` (CRUD, Gate `mandants.manage`), `/api/admin/mandants/{id}/teams` (CRUD, Gate `teams.manage`); Logo/Header-Upload validiert (mimes, ≤2 MB, private Disk), Slug-Sicherheit
- [ ] Super-Admin-Frontend: Mandanten-Liste + Formular (Logo/Header, Impressum/Datenschutz, SMTP, teams_enabled-Toggle), Teams je Mandant
- [ ] Tests: PHPUnit (CRUD, Upload-Validierung, Domain-Constraints, teams_enabled-Auswirkung, IDOR via Gates), Playwright `@feature:admin:mandant`

**P2b — Kategorien + Events (Delegieren):**
- [ ] `Category` (mandant_id, team_id nullable = Override, name, slug, description, erbt vom Mandant, Team überschreibt), `Event` (mandant_id, team_id nullable, title, date, venue override, competition, deadline default+override, active)
- [ ] API: `/api/categories`, `/api/events` (Gate `categories.manage`/`events.manage`; team_admin nur eigene Team-Events); Scopes `forMandant`/`forTeam`
- [ ] Mandant-/Team-Admin-Frontend: Kategorie-/Event-CRUD mit Override-Logik (Team überschreibt Mandant)
- [ ] Tests: PHPUnit (CRUD, Scope-Isolation, Override-Precedence Mandant→Team deterministisch), Playwright `@feature:admin:category` / `@feature:admin:event`

**P2c — Benutzer + Freigaben-Basis (Delegieren):**
- [ ] Mandant-Admin: Benutzerliste + Rollen-Zuweisung je Mandant (Gate `users.manage`), Team-Admin sieht nur eigenes Team
- [ ] Team-Admin **read-only Sicht auf Verbands-Akkreditierungen eigener Personen** (D7, Gate `accreditations.view` — Ressourcen in P3)
- [ ] Tests: PHPUnit (Zuweisung, Isolation), Playwright `@feature:admin:users`

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

## 🔍 Open Follow-ups (verifiziert, aber offen)

- [ ] **F2 (low)** JWT-Parser-Kette auf Cookie-only beschränken (Header/Query/Form deaktivieren) → P7-Hardening.
- [ ] **F3 (low)** `local`-Disk `serve => true` teilt Root mit `private` → P7-Hardening.
- [ ] **F4 (low)** `activation_token` als sha256-Hash statt Klartext speichern → P7-Hardening.
- [ ] **F5 (low)** Media-Uploads: Kontingent/Rate-Limit (Porträt, Presse-ID, Anhänge) → P7-Hardening.
- [ ] **F7 (info)** Mandant-Check nur beim Login — P2/P3: Ressourcen (Teams, Kategorien, Events, Akkreditierungen) pro Request über `forCurrentMandant()`-Scopes scopen.
- [ ] **B2 (low)** Auth-Throttle `5,1` teilt einen Bucket für register+login (inkl. `auth.spec` `@smoke`-Retry-Risiko) — bei CI-Retries 429 möglich; falls erneut auftritt: benannte Limiter trennen. (CI aktuell grün, kein Flake.)
- [ ] **B3 (info)** Prod: `trustHosts()` aus `mandant_domains` befüllen → P7.
- [ ] **P1d-F2 (low)** `roleAssignmentForMandant()` (User.php) wertet nur die ERSTE Rollen-Zuweisung je Mandant aus (`orderBy role_user.id`) — bei mehreren Rollen pro Mandant Unter-Granting (fail-closed, keine Escalation). Fix in **P2c** (Rollen-Zuweisung): Union über Rollen oder Single-Role-Enforcement entscheiden; Unique-Index `role_user_scope_unique` erlaubt mehrere Rollen.
- [ ] **P1a-B1 (low)** Unbekannte Hosts nicht negativ cachen (60s-TTL; aktuell bewusst „Unknown hosts are not cached") → Hardening.
- [ ] **P1a-B2 (low)** Referer-Fallback (aktuell `local`-env-beschränkt, jeder Referer-Host) auf Vite-Origin `localhost:5173` einschränken.
- [ ] **P1a-B4 (low)** `config/mandants.php`-Kommentar „Primary-Mandant im Cache" vs. `MandantContext::default()` („Not cached") widersprüchlich → Kommentar korrigieren.
- [ ] **P0-Fix-F3 (low)** Prod-Guard für Default-Admin (Seeder-Admin nur außerhalb von Prod) → P7 Env-Hardening.
- [ ] **P1c (info)** `@feature:profile`-Playwright-E2E folgt nach Frontend-UI (P2).

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
