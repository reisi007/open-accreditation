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

**P2b — Kategorien + Events (Delegieren):**
- [ ] Models + Migrationen: `Category` (mandant_id FK, team_id nullable = Override, name, slug, description, unique je (mandant_id, slug) bzw. (mandant_id, team_id, slug)), `Event` (mandant_id FK, team_id nullable, title, date, venue nullable (Default = Team-Heimstätte, überschreibbar), competition, deadline_start/deadline_end (Mandant-Default überschreibbar), active)
- [ ] API: `/api/admin/categories` + `/api/admin/events` (CRUD, Gates `categories.manage`/`events.manage`; team_admin nur eigene Team-Events; Scopes `forMandant`/`forTeam`); Team-Admin-Frontend-Zugriff via Team-Scope
- [ ] Frontend: Kategorie-/Event-CRUD (Mandant-Ebene + Team-Override anlegen/bearbeiten), Frist-Defaults, Übernahme Heimstätte
- [ ] Tests: PHPUnit (CRUD, Scope-Isolation, Override-Precedence Mandant→Team deterministisch, team_admin-Isolation, event active-Filter), Playwright `@feature:admin:category` / `@feature:admin:event`

**P2c — Benutzer + Freigaben-Basis (Delegieren):**
- [ ] API: `/api/admin/mandants/{id}/users` (Liste) + Rollen-Zuweisung (Gate `users.manage`; Team-Admin sieht nur eigenes Team); **P1d-F2 hier lösen** (mehrere Rollen je Mandant: Union über Rollen oder Single-Role-Enforcement — Entscheidung dokumentieren)
- [ ] Frontend: Benutzerliste + Rollen-Edit (mandant_admin/team_admin/user/verifier je Mandant, super_admin nur global)
- [ ] Team-Admin read-only Sicht auf Verbands-Akkreditierungen eigener Personen (D7): Gate-Semantik in P3e mit echten Ressourcen nutzen (P3c/P3e)
- [ ] Tests: PHPUnit (Zuweisung, Isolation, P1d-F2-Regression), Playwright `@feature:admin:users`

### P3 — Öffentliches Portal + Anmeldung + Allocation-Engine 🟡

**P3a — Öffentliches Portal (Delegieren):**
- [ ] Landing: Mandant-/Team-Kacheln (aus `is_active` + `teams_enabled`), Sprachumschalter DE/EN (bestehend)
- [ ] Veranstaltungskalender: Filter Team/Typ (Wettbewerb), nur aktive Events, Akkreditierungsfrist-Countdown
- [ ] Event-Detail: Titel, Datum, Ort (Heimstätte/Override), Wettbewerb, Frist (Start/Ende + Countdown), Event-Verwalter-Kontakt (mandant/team-admin E-Mail)
- [ ] Öffentliche API: `GET /api/portal/mandants` (Kacheln), `GET /api/portal/events` (Kalender-Filter), `GET /api/portal/events/{id}` — auth-frei, mandant-scoped
- [ ] Tests: PHPUnit (Kalender-Filter, active-Filter, Frist-Logik), Playwright `@feature:accreditation`

**P3b — Selbstanmeldung (Delegieren):**
- [ ] Models + Migrationen: `accreditations` (mandant_id, team_id nullable, category_id, scope enum event|league|season, event_id nullable, quota int, deadline_start/end, auto_approve bool), `applications` (accreditation_id, user_id, status enum requested|approved|denied|blacklisted, applied_at, priority bool, reason nullable), `blacklists` (mandant_id, email nullable, domain nullable, note)
- [ ] API: `GET /api/accreditations` (öffentliche Verfügbarkeit je Event), `POST /api/accreditations/{id}/apply` (Antrag, Quota offen + Frist-Check, Status `requested`, Photo/Presse-ID/Anhänge aus Profil), `GET /api/applications` („Meine Akkreditierungen")
- [ ] Frontend: Antrag-Seite (Kategorie/Scope wählen, Verfügbarkeitsanzeige, Absenden), „Meine Akkreditierungen"-Übersicht mit Status
- [ ] Tests: PHPUnit (Antrag-Logik, Quota/Frist-Prüfung beim Antrag, Doppel-Antrag verhindert, Mandant-Scoping), Playwright `@feature:accreditation`

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
- [ ] Mandant-/Team-Admin: Antragsliste mit Status, Einzel-Freigabe/Ablehnung (mit Grund), Massenfreigabe-UI (alle / erste X), Blacklist-Verwaltung (Team-Admin read-only Sicht D7 hier mit echten Ressourcen)
- [ ] Tests: PHPUnit (Freigabe-Aktionen, Status-Übergänge, Blacklist-CRUD), Playwright `@feature:accreditation`

### P4 — Ausweis (Template, PDF, CSV/Excel, QR) 🟡

- [ ] `badge_templates` (Layout-JSON: Felder/Logo/Header/Farben) + Feld-Editor-UI (MVP: Feld-Set + Positionen, drag-frei per Koordinaten/Grid), Preview
- [ ] PDF-Export (dompdf/Chromium) + CSV/Excel-Export (Serienbrief) — auth-gated Download
- [ ] QR-Code (signed Token, HMAC/App-Key) + öffentliche Prüfseite (Foto/Status) + Ordner-Scan-View (mobile-friendly)
- [ ] Tests: PHPUnit (Token-Signatur/Verifikation inkl. Manipulation, Template-Render, Export-Dateien), Playwright `@feature:badge`

### P5 — E-Mail-Workflow 🟡

- [ ] Mailables: Aktivierung (besteht), Freigabe/Ablehnung (mit Grund), Frist-Reminder (vor Fristende), Pass-Versand (PDF/Wallet-Link)
- [ ] SMTP je Mandant (settings-Overlay, `smtp_config` aus P2a; Mailer-Service pro Mandant), Queue
- [ ] Tests: PHPUnit (Mailables inkl. Inhalt, SMTP-Config-Override, Queue-Jobs, Reminder-Zeitpunkt), Mailpit-Assertions

### P6 — Wallets (PKPASS) + Sub-Karten 🟡

- [ ] Apple Wallet (.pkpass) + Google Wallet: Generierung/Signierung, Ausgabe via E-Mail/Link
- [ ] Park-/Sitzkarten als eigene Ausweis-Typen mit eigener Vorlage
- [ ] Tests: PHPUnit (Pass-Generierung/Validierung, Signierung), Playwright `@feature:wallet`

### P7 — Polish + Deploy 🟡 **AUF HALT — Go-Live wartet auf Benutzer-Freigabe**
> Alles bis einschließlich P6 wird umgesetzt. P7 (Caddy multi-Domain, Env-Hardening, Prod-Deploy)
> erst nach expliziter Freigabe des Benutzers.

- [ ] Caddy/Reverse-Proxy-Konfig (multi-Domain), Env-Hardening (APP_KEY/JWT_SECRET-Guards, P0-Fix-F3 Default-Admin)
- [ ] Hardening-Follow-ups: F2 (JWT-Parser cookie-only), F3 (Disk-serve), F4 (activation_token hash), F5 (Upload-Kontingent), B2 (Auth-Throttle trennen), B3 (trustHosts), P1a-B1/B2/B4
- [ ] Vollsuite grün (PHPUnit + Vitest + Playwright), `@smoke` nach jedem Schritt

## 🔍 Open Follow-ups (verifiziert, aber offen)

- [ ] **F2 (low)** JWT-Parser-Kette auf Cookie-only beschränken (Header/Query/Form deaktivieren) → P7-Hardening.
- [ ] **F3 (low)** `local`-Disk `serve => true` teilt Root mit `private` → P7-Hardening.
- [ ] **F4 (low)** `activation_token` als sha256-Hash statt Klartext speichern → P7-Hardening.
- [ ] **F5 (low)** Media-Uploads: Kontingent/Rate-Limit (Porträt, Presse-ID, Anhänge) → P7-Hardening.
- [ ] **F7 (info)** Mandant-Check nur beim Login — P2/P3: Ressourcen (Teams, Kategorien, Events, Akkreditierungen) pro Request über `forCurrentMandant()`-Scopes scopen.
- [ ] **B2 (low)** Auth-Throttle `5,1` teilt einen Bucket für register+login (inkl. `auth.spec` `@smoke`-Retry-Risiko) — bei CI-Retries 429 möglich; Fix (benannte Limiter `login`/`register`, je 10/min, getrennte Buckets) im **P2b**-Backend-Paket.
- [ ] **P2a-RL (low)** Admin-Write-Routen (`/api/admin/*`) tragen kein Rate-Limit → für P7-Hardening benannte Limiter vorsehen.
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
