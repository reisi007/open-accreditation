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

### P3 — Öffentliches Portal + Anmeldung + Allocation-Engine 🟡

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
- [ ] **P2a-RL (low)** Admin-Write-Routen (`/api/admin/*`) tragen kein Rate-Limit → für P7-Hardening benannte Limiter vorsehen.
- [ ] **P2b-F8 (info)** Event-Partial-Update: nur `deadline_start` ODER nur `deadline_end` wird nicht gegen gespeicherten Gegenwert validiert (Rand-Datenkonsistenz, kein Exploit) → P7 oder akzeptiert.
- [ ] **P3a-F1 (info)** Countdown-Plural-Workaround in `DeadlineCountdown.tsx` (String-Interpolation statt Lingui-ICU-Plural) → akzeptiert.
- [ ] **P3a-F2 (info)** E2E-Daten-Ansammlung: `ensurePrimaryMandantActivePortalEvent` erzeugt Events ohne Cleanup → lokale Kosmetik, akzeptiert.
- [ ] **P3c-F1 (info)** `skipped_blacklist` modusabhängige Semantik: Selection („erste X") → bleibt `requested`; approveAll → `denied` → in P3e-UI-Zähler klären.
- [ ] **P3c-F2 (info)** Blacklist-Verwaltung: nur Tabelle/Modell, **keine CRUD-Routen** → P3e (mandant-scoped CRUD + Tests; Unique-Constraint fehlt).
- [ ] **P3c-F3 (info)** VIP-Setzung: `priority` wird beim Apply hart auf false gesetzt → P3e braucht Admin-Weg (Person/Domäne laut D8).
- [ ] **P3c-F4 (info)** Test-Coverage-Nuancen (Blacklist+VIP-Kombi, case-insensitiv, approveAll mit bestehenden approved, `mode` fehlt/non-int limit, Exakt-Fit Quota) → optional nachziehen (P3e-Paket oder später).
- [ ] **P3b-F2 (info)** `applied_at` vs `created_at`: API exponiert `created_at`; `features/02-domain-model.md` ggf. auf `created_at` präzisieren → P3e-Cleanup.
- [ ] **P3b-F3 (info)** Medien-Snapshot beim Antrag (Foto/Presse-ID/Anhänge): Entscheidung in P3e — Freigabe-Sicht bezieht Medien des Antragstellers.
- [ ] **P2b-F5 (info)** `is_team_override` = `team_id !== null` (Semantik-Kosmetik: Badge auf jeder Team-Kategorie) → akzeptiert/dokumentieren.
- [ ] **P2c-F3 (low)** `role_user.team_id` hat keine FK/Cascade auf `teams` → verwaiste team_admin-Zuweisungen bei Team-Delete (kein Escalation, nur Datenhygiene) → bei P3e (Team-Lösch-Flow) Cleanup/Cascade vorsehen.
- [ ] **P2c-F4 (info)** super_admin nähert „aktuellen Mandant" als Primär-Mandant an (Dev ok; Nicht-Primär-Domain zeigt falsche Teams) → Multi-Domain-Admin-UX in P3/P7.
- [ ] **B3 (info)** Prod: `trustHosts()` aus `mandant_domains` befüllen → P7.
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
