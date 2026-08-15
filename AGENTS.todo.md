# Task Board — open-accriditation

> Stand: 2026-08-14. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
>
> **Scope-Entscheidung (Benutzer, 2026-08-14):** ALLE offenen Verbesserungen werden JETZT umgesetzt:
> (1) UI-Review-Befunde (alle Severities) + Formular-Abstände-Audit → implementieren,
> (2) P8b Mandant-Bilder Self-Service + P8-Rest → implementieren (ausgenommen Release/Deploy und
> Caddy-Änderungen; Caddy-SOLL-Doku wird basierend auf realem `~/dev/caddyfile/Caddyfile` erstellt),
> (3) P7-Hardening-Follow-ups (F2–F5, B2/B3, P2a-RL, P4-F1, P5-F2, P1a-B1/B2/B4, P0-Fix-F3, P3d-F2,
> P2c-F3, P4-F4) → mitnehmen. Abschluss = erneute visuelle Überprüfung (Screenshot-Suite + Vision),
> KEIN finaler User-Test. Git-Commits zwischendurch.
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

### P4 — Ausweis (Template, PDF, CSV/Excel, QR) ✅

### P7 — Polish + Deploy 🟡 **AUF HALT — Go-Live wartet auf Benutzer-Freigabe**
> **Einziger verbleibender Block:** Alle Umsetzungsphasen P1–P6 sind abgeschlossen (verifiziert, APPROVED).
> P7 (Caddy multi-Domain, Env-Hardening, Prod-Deploy) wird erst nach expliziter Freigabe des Benutzers
> umgesetzt — dieser Block wartet auf die Benutzer-Freigabe.

- [ ] Caddy/Reverse-Proxy-Konfig (multi-Domain), Env-Hardening (APP_KEY/JWT_SECRET-Guards, P0-Fix-F3 Default-Admin)
- [ ] Hardening-Follow-ups: F2 (JWT-Parser cookie-only), F3 (Disk-serve), F4 (activation_token hash), F5 (Upload-Kontingent), B2 (Auth-Throttle trennen), B3 (trustHosts), P1a-B1/B2/B4
- [ ] Vollsuite grün (PHPUnit + Vitest + Playwright), `@smoke` nach jedem Schritt

### P8 — UI-Review-Skill + Branding-Erweiterung 🟡 (2026-08-14)
> **Ziel:** (1) Wiederholbarer `ui-review`-Skill (Playwright-Screenshots aller Seiten leer/gefüllt → Vision-Analyse),
> global registriert via `~/.config/opencode/opencode.jsonc`, Quelle in `.opencode/skills/ui-review/`.
> (2) Mandanten-Logo-Varianten **direkt in `frontend/public/`** (keine `brands/`-Struktur) im React-Projekt als
> Fallback + Einbindung auf der Startseite; Caddy liefert später pro Mandant auf Datei-Basis, React-Dateien
> dienen als Fallback (SOLL: `features/03-caddy-brand-files.md`).
> **Kein Commit** bis Benutzer-Freigabe.

- [ ] Skill `ui-review`: `.opencode/skills/ui-review/` mit `SKILL.md` (Workflow), `references/` (Checkliste, Befund-Report, Harness-Anleitung) + generische Templates → DoD
- [ ] Global registrieren: `~/.config/opencode/opencode.jsonc` → `"skills": ["/Users/florianreisinger/dev/open-accreditation/.opencode/skills"]`
- [ ] Projekt-Harness: `frontend/playwright.screenshots.config.ts` (eigenes `testDir: tests/screenshots`, eigener outputDir), `frontend/tests/screenshots/ui-review.config.ts` (Routen-Matrix filled/empty, Desktop+Mobile), `ui-screenshots.spec.ts` (Tag `@screenshot`), Helper `empty-mandant.ts` (eigener leerer Mandant, Domain `empty.localhost`)
- [ ] npm-Script `test:screenshots` (eigenständig, NICHT in Standard-E2E); Output gitignored (`test-results/ui-screenshots/`)
- [ ] Erstlauf `pnpm test:screenshots` (Screenshots aller Seiten leer/gefüllt, Desktop+Mobile)
- [ ] **Vision-Analyse** der Screenshots (Batches ≤10 Bilder) → Issues mit Severity + Datei:Zeile identifizieren und in `AGENTS.todo.md` vermerken (Umsetzung der gefundenen Fehler ist NICHT Ziel — nur Doku)
- [ ] Logo-Varianten (Portal-Muster) erstellen → `frontend/public/` (ROOT, KEIN `brands/`-Ordner) als Fallback
- [ ] Startseite (PortalHomePage) bindet Logo korrekt ein: Mandant-Logo aus API, Fallback auf `/logo.svg` (React-Asset)
- [ ] Caddy-SOLL: pro Mandant Datei-Basis-Auslieferung dokumentieren (React-`frontend/public/` als Fallback; Caddy serviert per Host einfach andere Dateien für `/favicon-*`, `/logo.svg`, `/site.webmanifest`) → `features/03-caddy-brand-files.md`

### P8b — Mandant-Bilder: Self-Service + Super-Admin-Übersicht (aus GAP-Analyse 2026-08-14)
> **Ziel:** (a) Mandant-Admin kann eigene Logo/Header-Bilder anpassen; (b) Super-Admin sieht/steuert Bilder aller Mandanten/Subdomains.
> **Befund:** (a) kompletter GAP (keine Permission, keine selbstgescopten Routen, keine UI, keine Tests); (b) größtenteils da — fehlen Logo-Thumbnail in Liste + Subdomain-Preview.

- [ ] **Backend (a):** neue Permission `mandant.media.manage` in `config/permissions.php` zur `mandant_admin`-Zeile; AuthServiceProvider registriert Gate automatisch
- [ ] **Backend (a):** selbstgescopte Routen + Controller-Methoden (eigenes Mandant via `MandantContext::currentId()`/`role_user`), z. B. `POST/GET/DELETE /api/mandant/logo|header` (nicht `mandants.manage`, sondern neue Gate)
- [ ] **Backend (b):** Logo-Thumbnail im `MandantListPage`-Payload vorhanden (kein Backend-Change nötig, `logo_url` wird bereits serialisiert)
- [ ] **Frontend (a):** Route/Seite für `mandant_admin` (RequireRoles super_admin+mandant_admin), `MediaField` wiederverwenden → Admin-Menüpunkt „Logo & Header" (nur mandant_admin+super_admin)
- [ ] **Frontend (b):** `MandantListPage` um Logo-Thumbnail-Spalte erweitern; „Open portal"-Link pro Domain (externer Tab) prüfen
- [ ] **Tests:** PHPUnit (Self-Service-Flow mandant_admin upload/del + 403 für team_admin/user; super_admin weiterhin alle Mandanten) + Playwright-E2E `@feature:admin:mandant` (Thumbnail, Self-Service-Upload) + `RolePermissionTest`-Matrix erweitern
- [ ] **Doku:** `features/`-SOLL (Rollen-Modell: mandant_admin darf eigene Bilder, super_admin alle)

### 🖼️ UI-Review-Befunde (Vision-Analyse 2026-08-14, Screenshot-Erstlauf)
> **Ziel:** Screenshots mit Vision-Agent analysieren → Issues in `AGENTS.todo.md` vermerken. **Umsetzung ist KEIN Ziel** (nur Doku; Fixes später als eigene Tasks).
> **Basis:** 7 Screenshots aus `test-results/ui-screenshots/` (Erstlauf unvollständig/flaky — siehe Flakiness-Untersuchung). Kein Commit.
>
> **Status (Scope-Entscheidung 2026-08-14):** Befunde werden JETZT als Fixes implementiert (alle Severities). Umsetzung über Implementer-Subagenten, danach erneute Screenshot-Suite + Vision-Verifikation.

- [ ] **high** `admin-freigaben` (filled): extrem lange Tabelle ohne Pagination/Virtualisierung, Sticky-Header, Ergebnisanzahl → Übersicht/Navigation fehlt.
- [ ] **high** `admin-freigaben` (filled): sehr dichte Zeilen + winzige Status-/Action-Controls im Full-Page-Screenshot schwer lesbar.
- [ ] **high** `admin-users` (filled): extrem hohe Tabelle ohne Pagination/Sticky-Header/Result-Count.
- [ ] **high** `admin-users` (filled): „Rolle bearbeiten"-Buttons wickeln um (Action-Spalte zu schmal).
- [ ] **medium** `admin-badge-templates` (empty): leere Liste = nackte Tabellenmeldung ohne Illustration/CTA (Button `+ Neu` visuell abgetrennt).
- [ ] **medium** `admin-freigaben` (empty): leeres Ergebnis nur als Fließtext unter der Tabellenüberschrift, kein Filter-Empty-State.
- [ ] **medium** `admin-users` (empty): kein Unterschied „keine Benutzer" vs. „Suchtreffer leer"; kein Invite-/Create-CTA.
- [ ] **medium** `admin-freigaben` (filled): Antragsteller-/Akkreditierungs-Labels wickeln mehrzeilig um, uneinheitliche Zeilenhöhen.
- [ ] **medium** `admin-freigaben` (filled): mehrere Status-Farben ähnlich/teils nur Farbcodierung → explizite Text-/Icon-Semantik prüfen.
- [ ] **medium** `admin-freigaben` (filled): Action-Controls stapeln je Zeile unterschiedlich → konsistentes Action-Group-Muster.
- [ ] **medium** `admin-users` (filled): lange E-Mails wickeln stark um → Clamp/Truncate + Tooltip/Detail.
- [ ] **medium** `admin-users` (filled): Tabellenbreite zu schmal für E-Mail-/Action-Spalten.
- [ ] **medium** `meine-akkreditierungen` (filled): Single-Row-Card-Layout kann bei schmaleren Viewports eng werden → responsive Stapel-Layout.
- [ ] **low** `admin-badge-templates` (filled): „Standard"-Spalte leer bei Nicht-Default → Platzhalter `—`.
- [ ] **low** `admin-badge-templates` (filled): lange generierte Template-Namen schwer scanbar → Truncate/Tooltip, sprechende Namen.
- [ ] **low** `meine-akkreditierungen` (filled): generierte IDs dominieren Card → lesbarer Name, ID als Sekundär-Metadaten.
- [ ] **low** Empty-States allgemein: viel ungenutzter Weißraum → zentrierte Empty-State-Panels.
- [ ] **low** `admin-freigaben` (empty): Filter vertikal gestapelt trotz horizontalem Platz → kompaktes Filter-Grid.
- [ ] **low** `admin-users`: Suchfeld proportional zu schmal → Toolbar-Layout.
- [ ] **info** Branding (grünes Akkreditierungs-Icon + „Akkreditierung") überall korrekt; keine kaputten Bilder, keine gemischten Sprachen.

### 🧪 Screenshot-Suite: Flakiness-Befunde (Untersuchung 2026-08-14, kein Fix)
> **Ursache der unvollständigen Erstläufe (nur 4–7 PNGs von 58 erwarteten):** 9 Fehler + 16 Skips; Artefakte stammen aus einem grep-gefilterten Debug-Subset, nicht der vollen Matrix. Kein Fix (Umsetzung kein Ziel).

- [ ] **high (R1)** Mobile-Navbar-Overlap: `navbar-center` (`App.tsx:64-111`) überlappt `Anmelden` bei 360px (Galaxy A55) → ALLE Mobile-Tests schlagen mit 120s-Timeout fehl (`Verifizieren` interceptet). Fix: responsive Collapse/Drawer + `@mobile`-Assertion.
- [ ] **high (R2)** Empty-State-Login: Seeds legen User auf **Primary-Mandant** an (`seeds.ts:34-37`, `admin-data.ts:4`), Login läuft aber auf `empty.localhost` → 403 „nicht registriert" (Konten sind pro Mandant). Fix: Seeds für `empty`-States via `empty.localhost`-API-Kontext anlegen, ohne Application; irreführende `note` in `ui-review.config.ts:140` korrigieren.
- [ ] **medium (R3)** Volle Matrix wurde nie gefahren; `tests/screenshots/debug.spec.ts` (Scratch, „Too many arguments") bricht jede Suite (testMatch `**/*.spec.ts`). Fix: debug-Spec löschen/umbenennen, vollen Lauf `--grep @screenshot` (58 Instanzen).
- [ ] **low/medium (R4)** Login-Rate-Limit (`throttle:login` 40/min/IP, `AppServiceProvider.php:42-45`) → 429-Risiko bei ~60+ Logins im Parallellauf; `settleAndCapture` fixed 250ms (`ui-screenshots.spec.ts:75-80`) fragil unter Vite-HMR; `ensureEmptyMandant` (`empty-mandant.ts:81-87`) schluckt alle Nicht-201-Domain-Antworten still; PNG-Existenz nach `page.screenshot` wird nicht assertiert.
- [ ] **Hinweis:** Screenshot-Erstlauf hat `test-results/ui-screenshots/` mehrfach überschrieben (Vision-Basis von 7 PNGs stammt aus 1. Lauf); Wiederholung erst nach Fixes der Suite.

### 📐 Formular-Abstände: Audit (Code + Vision, 2026-08-14)
> **Verdikt:** Field-Stack konsistent (`flex flex-col gap-4` in 13/13 Formularen, `form-control` + daisyUI-label), **aber Abstand zum Submit-Button inkonsistent**. Kein Fix (Doku).
>
> **Status (Scope-Entscheidung 2026-08-14):** Befunde werden JETZT als Fixes implementiert.

- [ ] **high** Submit-Abstand abweichend: `LoginPage.tsx:99` `mt-2` auf Button (24px statt 16px); `VerifyPage.tsx:92` ohne Wrapper/`mt`; `MandantDetailPage.tsx:315` Domain-Form `gap-2` (8px); `BlacklistForm.tsx:85-86` plain `<div>` + `btn btn-sm`
- [ ] **medium** Submit-Wrapper fehlt in 4 Formen (LoginPage, VerifyPage, BlacklistForm, Domain-Form) — Standard ist `flex flex-wrap items-center gap-2` + `btn btn-primary` (9/13)
- [ ] **low** `BadgeTemplateForm.tsx:89` `grid gap-6` statt `gap-4` (einziges abweichendes Grid)
- [ ] **low** Error-Hint ohne `mt-1`: `EventForm.tsx:148`, `AccreditationForm.tsx:173`, `SubAccreditationForm.tsx:106`
- [ ] **low** `TeamForm.tsx:31` styled sich selbst als Card (`rounded-box bg-base-100 p-4`) — alle anderen Formulare bekommen Container vom Parent
- [ ] **low** `BlacklistForm.tsx` `input-sm`/`btn-sm` — einzige Small-Variante
- [ ] **info** Vision (apply.png): Zeilen-Rhythmus konsistent, Submit-Button im Screenshot nicht sichtbar (Form läuft weiter) → Abstand oben/unten nicht verifizierbar

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
- [ ] **P3c-F4 (info)** Test-Coverage-Nuancen (Blacklist+VIP-Kombi, case-insensitiv, approveAll mit bestehenden approved, `mode` fehlt/non-int limit, Exakt-Fit Quota) → optional nachziehen (P3e-Paket oder später).
- [ ] **P3d-F2 (low)** `'UNIQUE'`-Match case-sensitiv schlägt auf Postgres fehl (SQLSTATE 23505; P3b-Muster gespiegelt) → treiber-agnostischer Check → P7-Hardening.
- [ ] **P3d-F5 (info)** keine P3d-SOLL-Doku → Doku-Abschnitt (Sub-Allokation + Freigabe/Blacklist SOLL) wird im P4-Batch erstellt.
- [ ] **P3e-B3 (info)** Controller-Scope-/`EscapeLike`-Duplikation (4 Admin-Controller) → Refactoring-Kandidat (P7).
- [ ] **P3e-B4 (info)** `fetchAllAdminSubAccreditations` macht N parallele Requests → dedizierter Filter-Endpoint (später).
- [ ] **P3e-B5 (info)** E2E-Rate-Limiter-State: 7-Tage-TTL im DB-Cache → `php artisan cache:clear` vor E2E-Läufen in `e2e-up.sh`/CI-Doku aufnehmen (Determinismus).
- [ ] **P3b-F2 (info)** `applied_at` vs `created_at`: API exponiert `created_at`; `features/02-domain-model.md` ggf. auf `created_at` präzisieren → P3e-Cleanup.
- [ ] **P2b-F5 (info)** `is_team_override` = `team_id !== null` (Semantik-Kosmetik: Badge auf jeder Team-Kategorie) → akzeptiert/dokumentieren.
- [ ] **P2c-F3 (low)** `role_user.team_id` hat keine FK/Cascade auf `teams` → verwaiste team_admin-Zuweisungen bei Team-Delete (kein Escalation, nur Datenhygiene) → bei P3e (Team-Lösch-Flow) Cleanup/Cascade vorsehen.
- [ ] **P2c-F4 (info)** super_admin nähert „aktuellen Mandant" als Primär-Mandant an (Dev ok; Nicht-Primär-Domain zeigt falsche Teams) → Multi-Domain-Admin-UX in P3/P7.
- [ ] **B3 (info)** Prod: `trustHosts()` aus `mandant_domains` befüllen → P7.
- [ ] **P1a-B1 (low)** Unbekannte Hosts nicht negativ cachen (60s-TTL; aktuell bewusst „Unknown hosts are not cached") → Hardening.
- [ ] **P1a-B2 (low)** Referer-Fallback (aktuell `local`-env-beschränkt, jeder Referer-Host) auf Vite-Origin `localhost:5173` einschränken.
- [ ] **P1a-B4 (low)** `config/mandants.php`-Kommentar „Primary-Mandant im Cache" vs. `MandantContext::default()` („Not cached") widersprüchlich → Kommentar korrigieren.
- [ ] **P0-Fix-F3 (low)** Prod-Guard für Default-Admin (Seeder-Admin nur außerhalb von Prod) → P7 Env-Hardening.
- [ ] **P1c (info)** `@feature:profile`-Playwright-E2E folgt nach Frontend-UI (P2).
- [ ] **P4-F1 (low)** CSV-Export: Formel-Injection (Zellen beginnend mit `=`, `+`, `-`), die in Excel ausgeführt werden → Zellen säubern/präfixen (P7-Hardening).
- [ ] **P4-F2 (low)** `AdminApplicationResource` macht Write-on-Read (lazy qr_token-Backfill während Serialisierung) → besser im Approval-Flow oder explizit (P7).
- [ ] **P4-F3 (low)** Verify nutzt `throttle:public` (geteilter Bucket mit Portal/Akkreditierungen) → eigener benannter Limiter (P7).
- [ ] **P4-F4 (info)** QR-Fixposition (20 mm unten rechts) kann Template-Felder überlappen → Layout-Schema um `qr`-Feld erweitern (später).
- [ ] **P4-F5 (info)** `features/`-SOLL-Doku: P6-Wallet-Vertrag (PKPASS) + Badges/QR-SOLL fehlen → kommen in **einem Doku-Batch** (P7-Vorbereitung).
- [ ] **P5-F2 (low)** Resend-Route ohne Rate-Limit (deckt sich mit P2a-RL; Admin-Vektor → Mail-Spam) → P7.
- [ ] **P5-F3 (info)** Reminder-Dedup ist pro Tag (bis 4 Mails im 3-Tage-Fenster) — bewusste MVP-Entscheidung (dokumentiert in `SendReminders.php`).
- [ ] **P5-F4 (info)** Queue-Integration (synchroner Versand als MVP-Entscheidung) → später/Post-MVP.
- [ ] **P6-B1 (info)** `relevantDate`-Semantik: nutzt `deadline_end` statt Event-Datum → mit Benutzer abstimmen (P7/Produkt).
- [ ] **P6-B2 (info)** ohne `GOOGLE_ISSUER_ID` leeres id-Präfix im Preview-Modus → dokumentiert, kein Risiko.

---

## 🔧 Workflow (delegieren + verifizieren)- Build-Agent: nur diese Datei + `AGENTS.md` (+ referenzierte Doku). Kein Produktiv-Code.
- Jeder TODO-Block → ein Implementer-Subagent (isoliert, präzise Anweisungen + Ziel-Dateien).
- Jede Umsetzung → ein **separater** Verifikator-Subagent (Tests/Lint/Build, Diff-Review **+ Architektur- und Security-Review** nach `AGENTS.md` §5; `critical`/`high` blockieren APPROVED).
- Visuelle Checks (Template-Editor, Ausweis-Layout, Screenshot-Abgleiche) → `vision`-Subagent.

## 🚀 Delegationsplan (Scope-Entscheidung 2026-08-14)

| Batch | Inhalt | Implementer | Verifikator |
|---|---|---|---|
| A | **UI-Befunde admin-freigaben** (Pagination/Sticky/Result-Count, Action-Group-Muster, Status-Semantik, Zeilendichte, Filter-Empty-State, Filter-Grid) | ✅ fertig (ses_ffe20c34bffeR1S5u01Q56uVIj) | ⏳ nach F |
| B | **UI-Befunde admin-users** (Pagination/Sticky/Result-Count, Action-Spalte, E-Mail-Truncate, Tabellenbreite, Empty-State-Differenzierung, Toolbar) | ✅ fertig (ses_ffe20c34affeInW1ED1cxrr6j5) | ⏳ nach F |
| C | **UI-Befunde badge-templates + meine-akkreditierungen + Empty-State-Panels** (`—`-Platzhalter, Truncate, responsives Card-Layout, ID als Sekundär-Metadaten, zentrierte Empty-States) | ✅ fertig (ses_ffe20c349ffeppDRj0oDqU9q2H) | ⏳ nach F |
| D | **Formular-Abstände** (Submit-Wrapper in Login/Verify/Blacklist/Domain-Form, `grid gap-4`, Error-Hint `mt-1`, TeamForm-Card, BlacklistForm-Klassen) | ✅ fertig (ses_ffe20c34cffeJScqTyU6tBfssw) | ⏳ nach F |
| E | **P8b Backend** (Permission `mandant.media.manage`, self-scoped Routen/Controller, Gate, PHPUnit + RolePermissionTest-Matrix) | ✅ fertig (ses_ffe1d666cffeoyILV8jfWDMg1P) | ✅ APPROVED |
| F | **P8b Frontend** (Route/Seite mandant_admin + MediaField, Admin-Menüpunkt, MandantListPage-Thumbnail + Open-Portal-Link, Playwright-E2E `@feature:admin:mandant`) | ⏳ läuft (ses_ffe20c34cffeJScqTyU6tBfssw) | ⏳ nach F |
| G1 | **P7-Hardening Backend (Config/Auth)** — F2 JWT-cookie-only, F3 Disk-serve, F4 activation_token-Hash, F5 Upload-Kontingent, B2 Auth-Throttle, B3 trustHosts, P2a-RL, P5-F2 Resend-RL, P1a-B1/B2/B4, P0-Fix-F3 | ✅ fertig (ses_ffe1d666cffeoyILV8jfWDMg1P) | ✅ APPROVED |
| G2 | **P7-Hardening Backend (Daten/Export)** — P4-F1 CSV-Formel-Injection, P3d-F2 Blacklist case-insensitiv, P2c-F3 role_user.team_id-FK, P2b-F8 Event-Partial-Update | ✅ fertig (ses_ffe1d666bffepfyqopg7UxQhx8) | ✅ APPROVED |
| H | **Verifikation + Screenshot-Suite + Vision** (je Batch separater Verifikator; danach `pnpm test:screenshots` + Vision-Analyse; Commits je Batch) | Backend ✅ APPROVED; Frontend ✅ APPROVED; Screenshot-Suite ✅ 55 PNGs grün; **Vision: 12 high (Mobile-Navbar), 5 medium, 10 low** | ⏳ Fix-Batch H1 läuft (ses_ffdcf9e53ffeiVWklEi5XnOBYC) |

Reihenfolge: E+F (P8b) und G2 (P7-Daten) laufen parallel zum Frontend (A–D); G1 (P7-Config) startet nach E (routes/api.php). Danach H (Screenshots + Vision). Commits je Batch.

## 📌 Offene Punkte / Risiken

- [ ] Projektname/Repo-URL festlegen (Verzeichnis heißt `open-accriditation`, Tippfehler)
- [ ] Postgres-Schema vs. SQLite-Tests: Portabilitätsregel aus `AGENTS.md` §2 durchsetzen
- [ ] Feld-Editor „Luxus": genauer Umfang der frei positionierbaren Felder klären (P4)
- [ ] Google-Wallet: API-Zugang/Issuer-Setup erforderlich (externer Schritt, P6)
