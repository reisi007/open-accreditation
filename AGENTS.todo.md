# Task Board — open-accreditation

> Stand: 2026-09-27. **Nur offene TODOs** (aktueller Plan). Architektur-SOLL wandert nach
> Umsetzung nach `features/`. Referenz: Sportdata „Accreditation Services" + Screenshots des
> Altsystems (Bundesliga/ÖFB) in `reference/`.
>
> **Status (2026-09-27):** P1–P8 umgesetzt und verifiziert. **Aktuell grün:** 1467 PHPUnit
> (7255 Assertions) + Pint 258, 304 Vitest, Lint + Build clean, E2E `@smoke` in CI gegen den
> Production-Build (Lauf `36317288916`, alle drei Jobs success). Zuletzt: Whole-Project-Review
> 2026-09-26 (40 Befunde, davon 1 critical + 1 high, alle behoben), Postgres-Portabilitäts-Gate
> 5/5 PASS auf echter PG 17.10, `MEDIA_ROOT`-Mount-Guard 7/7, Venue-Stammdaten (W12).
> **Verbleibend:** Go-Live (~20 Positionen, liegt per Anweisung unten) + der **„Nächster Batch"**
> weiter oben. Abgeschlossene Phasen stehen nicht mehr hier, sondern in `features/` bzw. in der
> Git-Historie (§4).
>
> Test-Regel (DoD): Backend → PHPUnit (SQLite `:memory:`), Allocation-Logik → eigene PHPUnit-Tests,
> Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E (getaggt).

---

## 📌 Nächster Batch — NICHT-Go-Live, priorisiert (2026-09-27)

> **Anweisung Benutzer 2026-09-27:** Go-Live bleibt liegen; als Nächstes werden die
> **Go-Live-freien** TODOs umgesetzt, in der Reihenfolge unten. Noch nichts gestartet —
> das Board ist der Plan, die Umsetzung folgt in einer eigenen Session.
> Jede Position ist §5-konform zu delegieren (Implementer ≠ Verifikator), mit
> Test-Forderung nach §3 DoD.

### Aus dem vollen E2E-Lauf: zwei Fehler, die ein `@smoke`-Gate nicht gesehen hätte

1. **Lost-Update-Fenster** (Position 1) — plus die **verschärfte EventForm-Variante**, die
   still den falschen Ort speichert statt leer zu bleiben.
2. **`VENUES_KEY` wurde nach Team-/Event-Mutation nicht invalidiert.** `VenueController::index`
   liefert abgeleitete `teams_count`/`events_count`, und `VenuesPage` blendet den destruktiven
   **`Löschen`**-Button anhand von `references(venue) === 0` aus — ohne Revalidierung sah ein
   frisch referenzierter Ort weiterhin löschbar aus und der Button hätte einen 409 geworfen.
   **Der E2E wäre an Position 1 also aus einem zweiten, unabhängigen Grund gescheitert.**

**Die Testlücke dahinter ist das eigentlich Lehreiche:** `venueFields.test.tsx:88` klickt eine
**bestehende** Option — also den **synchronen** Pfad `selectVenue()`. Der asynchrone Pfad
`handleCreate()` hatte **keinen** Test, und eine `teamFormUtils.test.ts` existierte gar nicht
(anders als `eventFormUtils.test.ts`). Ein Komponententest, der nur den synchronen Erfolgspfad
fährt, deckt ein asynchrones Verhalten prinzipiell nicht ab und meldet dabei „grün".

### Warum diese Reihenfolge

Nicht nach Schweregrad, sondern nach **Vertrauens-Kosten**: eine Position, deren
Verifikation fehlt, ist teurer als eine, deren *Umsetzung* fehlt. Deshalb steht
ganz oben nicht der große Feature-Block, sondern der Spec, der nie gelaufen ist —
solange `admin-venue.spec.ts` ungetestet ist, ist der Venue-Durchbruch eine
Behauptung, kein Fakt.

| # | Position | Aufwand | Warum hier |
|---|---|---|---|
| ~~**1**~~ | ~~BUG: Inline-Erstellung verliert `venue_id`~~ | — | Behoben (`c0c0547`), grün in `36328760503` (46 passed / 0 failed). Ursache war ein Lost-Update-Fenster, **kein** Remount. | **Ursache ist ein Lost-Update-Fenster, KEIN Remount** (die naheliegende Vermutung wurde gemessen und widerlegt: kein Remount, keine veraltete `field`-Closure, korrekte `created.id`-Form). `handleCreate` committet die Auswahl **zuletzt**, nach zwei Roundtrips (`POST /venues` + `await mutate()`), während `display = draft ?? selected?.name` schon den **getippten** Text zeigt → das Feld liest sich als „committed", der Formularwert ist noch `''`. Der E2E-Assertion `toHaveValue(venueName)` war deshalb **grün auf einer Lüge**. **Beim EventForm ist es schlimmer: dort wird nicht verworfen, sondern der ALTE Wert gerettet** (`venue_id: "11"` = Team-Heimort) — stilles Überschreiben statt leerem Feld. Fix: Auswahl committen sobald die Id existiert, Formular während laufender Orts-Mutation nicht abschickbar, `VENUES_KEY` nach Team-/Event-Mutation invalidieren. |
| ~~**1b**~~ | ~~ARIA: Button in `role="option"`~~ | — | Behoben (`c0c0547`): `<li role="presentation">` + Namens-`<span role="option">` + Button als Geschwister. Der alte Accessible Name war messbar `"E2E Heimstadion … inaktiv … reaktivieren"`. | Der `Reaktivieren`-Button liegt **innerhalb** `<li role="option" aria-disabled="true">`. Zwei reale Folgen, beide verifiziert: (a) `option` hat in WAI-ARIA **presentational children** — der Button verliert für Screenreader seine Rolle, die Reaktivierungsaktion ist für assistive Technik **unerreichbar**; (b) Playwright prüft `aria-disabled` auch auf Vorfahren und verweigert den Klick, der E2E scheitert seit dem Fix in Position 1 **deterministisch** an Zeile 148. Korrektur ohne Vertragsbruch: `<li role="presentation">` + innerer `<span role="option" aria-disabled>` (nur der Name) + Button als **Geschwister**. ⚠️ §7 verlangt danach den Vision-/UI-Review des Dropdowns. |
| ~~**2**~~ | ~~Volle E2E-Suite in CI verifiziert~~ | — | Erledigt, inkl. Messung. Erster voller Lauf (`36321788464`): 84 Tests, **45 passed / 1 failed / 38 skipped, 1,8 min Testzeit**. **Die 429-Hypothese ist widerlegt** — keine einzige `Too-Many-Attempts` trotz ~48 echter Tests seriell; der Login-Limiter (40/min) war **nicht** das Nadelöhr und bleibt unangetastet (dennoch auf Flakiness beobachten). **Der Lauf hat einen echten Produktfehler gefunden**, den das `@smoke`-Gate strukturell nicht sehen konnte — das ist der erste praktische Nutzen der Umstellung. |
| ~~**3**~~ | ~~Board-/SOLL-Korrektur Badge-Editor~~ | — | Erledigt (`fa146bf`). `features/badge-template-editor.md` war **veraltete SOLL** (§4): Titel/Anlass, Ist-Zeile, Zielbild, `image`-Abschnitt, Phasing-Kopf, Etappe 2/3 und der Interaktions-Teil der Editor-UX als superseded markiert; „Folge für die Umsetzung“ (5 Punkte inkl. 4 betroffener E2E-Tests) und „Offene Fragen“ ergänzt. Nicht behauptet: ob Schema `x/y/w/h` gültig bleibt — der Renderpfad ist nachweislich interaktionsunabhängig, das Editor-Schema steht als offene Frage. |
| **4** | **Badge-Editor: Drag-Interaktion zurückbauen** (2026-09-27 neu bewertet) | S2 | **Meine Prämisse war falsch und ist korrigiert:** ich hatte aus „keine DnD-Bibliothek im `package.json`“ auf „DnD nie umgesetzt“ geschlossen — ein Non-Sequitur, die Zieh-Interaktion ist **nativ per Pointer Events** gebaut (`BadgeCanvas.tsx:282-331`, `setPointerCapture`, px→mm gegen `getBoundingClientRect`; Commit `8634e40` wörtlich „FE3 badge editor drag&drop“). **Also Rückbau, kein Umbau — und kleiner als angenommen.** Erfüllt ist die Entscheidung heute schon: Raster ✅ (5-mm-Overlay + `snapToGrid`), konfigurierbare Labels ✅ (`BadgePropertiesPanel` mit X/Y/W/H in mm, Schriftgröße, Ausrichtung, Bildquelle, Fit), Auswahl ✅. **Zu entfernen ist nur:** Maus-Drag, Eckgriffe, Pfeiltasten-Nudge, magnetische Guides. ⚠️ Braucht Entscheidung: Rasterweite (5 mm beibehalten?) und ob der Pfeiltasten-Nudge als „konfigurierbar“ gilt. Danach §7 Vision-Review, weil 4 E2E-Tests betroffen sind. |
| **4b** | **WCAG 2.5.3: `aria-label` des Reaktivieren-Buttons** (low, 2026-09-27) | S2 | Der Button heißt sichtbar `Reaktivieren`, sein `aria-label` ist aber `"<Ortsname> reaktivieren"`. WCAG 2.5.3 („Label in Name“) verlangt, dass der sichtbare Text im Accessibility-Namen **enthalten** ist. Praktisch ist es vertretbar — die **Zeile** zeigt den Ortsnamen, nur die Buttons selbst sind identisch. Jede Änderung zieht die E2E-Locators mit (`admin-venue.spec.ts:148` sucht `${venueName} reaktivieren`) und braucht eine Entscheidung, ob der Ortsname sichtbar in die Zeile oder in den Button kommt. |
| **5** | **Venue-Schreibbreite `team_admin` (PRODUCT-DECISION)** — vom Benutzer **zurückgestellt** | S1 | Steht als eigene Position, **nicht selbst entscheiden** (siehe Session 2026-09-19). |
| ~~**6**~~ | ~~`{mandant}` 403/404-Unifikation~~ | — | Bewusst offen gelassen; die naive Route-Binding-Lösung entzieht `super_admin` den Zweck. Richtige Form ist die **403/404-Unifikation im Controller**, wie `TeamController`/`UserController` sie für ihre Ressourcen schon haben — als eigenes Ticket mit eigenen Tests. |
| **7** | ~~P2c-F4 Multi-Domain-Admin-UX~~ | — | Erledigt seit **`2e35df1` (2026-08-26)** — der Board führte dieselbe Position gleichzeitig als offen **und** als abgeschlossen. Reproduziert gegen das Dev-Backend mit echtem `Host`-Header: `/mandants/1/teams` liefert die 14 Teams des **URL**-Mandanten, `…/2/teams` korrekt 0; das alte Symptom existiert nicht. Abgedeckt durch `useAdminTeams.test.tsx:107-127` + `AdminTeamTest.php:426-448`. Die SOLL-Behauptung dazu war ebenfalls falsch und ist in `dda7912` korrigiert. **Variante (A) „Mandant aus der URL durchreichen" gibt es nicht** — alle 16 `useAdminTeams`-Consumer liegen auf host-skalierten Routen ohne Mandant-ID in der URL. |
| **7b** | **Venue-Auswahl schreibt still in den Host-Mandanten** (high, 2026-09-27) | S2 | Gefunden bei der Recherche zu Position 7, **empirisch gemessen** (nicht gelesen). Auf `/admin/mandants/{id}` mit abweichendem Host-Mandanten: `MandantDetailPage` lädt Teams über den **URL**-Mandanten, `useVenues()` → `GET /api/admin/venues` ist **host-skaliert** — `VenueController` hat anders als `TeamController` **keinen** `{mandant}`-Route-Parameter, und `store` setzt `'mandant_id' => $this->currentMandantId()`. **Folge:** Die Heimstätte-Auswahl bietet die Orte des falschen Mandanten an, und ein **Inline-Create legt die Zeile im Host-Mandanten an** — ohne Fehler. Erst der anschließende Team-Save scheitert mit 404 (`TeamController::assertVenueOfMandant`). Ein stiller Cross-Mandant-Write, kein Anzeigefehler. **Nur `super_admin` reichbar** (bei `mandant_admin` sind Host und URL identisch), also keine Privilege-Escalation — aber ein Datenintegritätsdefekt. **Lösungsraum:** mandant-adressierbarer Endpunkt `/api/admin/mandants/{id}/venues` + mandant-skaliertes `useVenues()`. Die **Produktfrage** (host-relativ belassen vs. echter Mandant-Switcher) ist bewusst **nicht** entschieden; beide Varianten samt Folgen stehen in `features/01-multi-tenancy.md`. |
| ~~**8**~~ | ~~§2-Portabilitätsregeln ergänzen~~ | — | Zwei verifizierte Fakten, die in `AGENTS.md` §2 **fehlen** und dort hinzugehören: (a) Postgres bricht bei Unique-Verletzung die **ganze Transaktion** ab (SQLSTATE 25P02), SQLite nicht — „try insert → catch → continue" braucht für §2-Portabilität ein **SAVEPOINT**; heute schreibt kein Code so etwas, die Regel ist also präventiv. (b) `LIKE … ESCAPE '\'`: auf PG ein semantisches No-op, auf SQLite **zwingend** — ohne die Klausel fail-closed (0 statt Über-Match). |
| ~~**8b**~~ | ~~Kein CI-Gate gegen echtes Postgres~~ | — | Die beiden neuen §2-Regeln (SAVEPOINT, `LIKE … ESCAPE`) sind **nur durch Messung** abgesichert, nicht durch eine wiederholbare Prüfung — §2/§7 nennen kein Gate, das die Testsuite je gegen Postgres fährt. Damit stehen sie still, sobald niemand mehr nachmisst. Ein wiederholbarer Lauf (`DB_CONNECTION=pgsql php artisan test`) wäre die naheliegende Absicherung. |
| **9** | ~~`migrate:fresh --seed` auf der Dev-DB~~ — vom Benutzer **fallengelassen** | — | Einziger Rest von Position 9. `BE-R8` ist dokumentiert (`bbef109`, mit wörtlichem Query-Beleg statt Prosa), `FE-R3` geprüft und aus dem Board entfernt. ⚠️ **Nicht ohne Freigabe ausführen:** `migrate:fresh` löscht die lokale Dev-DB inkl. angelegter Mandanten/Templates. Ein Agent hat im Rahmen der Debug-Session zudem `teams_enabled = true` auf dem Hauptmandanten **manuell** gesetzt — ohne das erreicht das E2E das Team-Formular gar nicht. Diese manuelle Änderung überlebt kein `migrate:fresh`; falls der Seeder `teams_enabled` nicht setzt, ist das eine Lücke, die vor dem Aufräumen zu klären ist. |
| ~~**10**~~ | ~~`PDF-VISION`-Pipeline~~ | — | Erledigt 2026-09-27: **durchlaufen, nicht behauptet.** Ein echtes 2-Seiten-Badge-PDF aus `BadgeRenderService::renderPdf` gerendert (PHPUnit-Fixture, SQLite `:memory:`, 13 970 Byte, `gs pdfpagecount` = 2), Pipeline ausgeführt, Bildmaße gemessen: A6 @200 dpi = **827×1165 px** (die Board-Referenz „1165×827" ist dieselbe Zahl, nur gedreht), `sips` = 298×420 px und nur Seite 1. Wiederholbar gemacht über `scripts/pdf-to-png-vision.sh`; alle vier Routen exit-getestet (Primär 0 · Fallback `gs`/`png16m` 0 · Fallback `sips` **3** · kein Werkzeug 1). **Die Board-Behauptung „transparente Pixel erscheinen schwarz" war für den `magick`-Pfad FALSCH und für `sips` wörtlich richtig:** die transparenten Pixel heissen dort `#FFFFFF00` und decken 89,8 % der Seite — schwarz werden sie erst beim Komponieren auf schwarzen Grund, wo die schwarze Badgeschrift **vollständig verschwindet** (0 sichtbare Tintepixel in einem 79 520-Pixel-Textband). Korrigiert in `features/badges-qr.md` samt allen Messwerten, zwei Werkzeug-Fallen und dem Nebenbefund, dass der Layoutkasten nicht clippt. Offen bleibt allein die **Produktentscheidung** über `background-color: #ffffff` — dort dokumentiert, ausdrücklich **nicht** umgesetzt. |

### Warte auf externe Schritte (nicht Go-Live, aber nicht von uns machbar)
- **Google Wallet (P6):** API-Zugang/Issuer-Setup beim Google nötig.
- **Nightly-E2E (striktes Profil):** der `@regression`-Cron ist eingerichtet, hat aber
  **null Läufe** — `gh run list --event schedule` ist leer. Der Cron kam erst am 2026-09-26
  (`3d9d3d3`), erster Zündtermin war der 2026-09-28 03:30 UTC. Der Flakiness-Detektor hat
  also noch nie gegen diesen Stand gearbeitet. Seit der CI-Umstellung vom 2026-09-27 deckt
  allerdings jeder Push dieselbe Testmenge ab — nur mit dem verzeihenden Budget.
- **Screenshot-/Vision-Loop (§7):** in dieser Session komplett ungelaufen.
- **`EXPLAIN ANALYZE`:** Index-*Form* ist verifiziert, nicht die Geschwindigkeit.

### Nicht in diesem Batch
- **Go-Live** (~20 Positionen: Pre-Prod-Domain, DNS, Caddy, Secrets, Deploy, Backup,
  Prod-Smoke) — liegt per Anweisung unten.
- **`P5-F4` Queue-Integration** — braucht Queue-Worker, ist Go-Live-Infrastruktur.

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
>
> **Stand 2026-09-27:** Der Halt ist nicht mehr nur „wartet auf Freigabe", sondern eine
> **ausdrückliche Anweisung**: Go-Live liegt, zuerst kommen die Go-Live-freien TODOs aus dem
> Abschnitt „Nächster Batch" oben (9 Positionen, S1–S3, alle delegierbar ohne Server/DNS/Secrets).
> Diese ~20 Positionen hier bleiben unangetastet, bis der Benutzer sie ausdrücklich wieder
> freigibt.
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
- [x] **Postgres-Portabilitäts-Gate — BESTANDEN 2026-09-27** (war fälschlich offen): alle
  5 Prüfpositionen PASS auf echtem **PostgreSQL 17.10**, komplette PHPUnit-Suite dort grün.
  Geprüft: `LIKE … ESCAPE '\'`, COALESCE-Ausdrucksindex, `NULLS LAST`,
  `smtp_config` (text + `encrypted:json`), E-Mail-Index. **Was der Lauf zusätzlich gefunden
  hat:** die SQLite-`->change()`-Falle bei Ausdrucksindizes (dokumentiert in
  `features/02-domain-model.md`, Commit `ce2dfc6`) — auf macOS prinzipiell unsichtbar, weil
  nur SQLite Ausdrucksspalten beim Table-Rebuild verliert. **Bewusst offen geblieben:**
  E2E-Suite gegen Postgres (Baum war mitten im Venue-Umbau) und `EXPLAIN ANALYZE` (Index-*Form*
  ist verifiziert, nicht die Geschwindigkeit) → Positionen 8/9 im „Nächster Batch".
- [ ] **Multi-Domain-UX-Gate (P2c-F4):** Admin-Zugriff auf Nicht-Primär-Domain prüfen (Teams-Anzeige super_admin) — **doppelt geführt**, dieselbe Position steht auch unter „Open Follow-ups". Einmal umsetzen als **Position 7 im „Nächster Batch", nicht doppelt.
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
- [x] Feld-Editor-Umfang — **geklärt 2026-09-27** (war fälschlich als „User-Input nötig" offen):
  Es gilt **Raster + konfigurierbare Labels**, **nicht** „voll frei positionierbar per Drag&Drop".
  ⚠️ **`features/badge-template-editor.md:7` widerspricht dem und zitiert die alte Entscheidung
  als Anlass** — das Dokument ist damit **veraltete SOLL** (§4), und die FE1–FE4-Commits wurden
  auf der alten Annahme gebaut. Korrektur + Umbau sind Positionen 3 und 4 im „Nächster Batch".
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
> Abgeschlossene Punkte entfernt: FE1-F2 (bereits vorhanden `80599f4`), FE1-F3 (Epsilon `80599f4`), FE1-F4 (host-Cache `80599f4`), E2E-Hygiene badge_images (`3bc1984`), BE-R1-F2 (RV-S3 Guard), BE-R1-F1 (FK-Migration `8e487c`), DOC-H-F1/F2 (bereits korrekt), DOC-H-F3 (Vollpfad `cef2403`), BE-R1-F3 (by-design: Tests nutzen `:memory:`, sqlite-Datei ist Dev-Artefakt), **PDF-VISION (Position 10, am 2026-09-27 durchlaufen → `scripts/pdf-to-png-vision.sh`; Messwerte in `features/badges-qr.md`)**.

### Full-Repo-Review 2026-08-26 (seit 2026-08-20) — Follow-ups (Verdict APPROVED, keine critical/high)
> Alle Punkte abgeschlossen (RV-S1 `0fe7544`, RV-S2/RV-A1/RV-U1 `8b370a8`, RV-S3 `a9750c2`, RV-S4/RV-A2/RV-U2 `0fe7544`, E2E-Hygiene badge_images `3bc1984`, RV-U3 dokumentiert `17498c4`).

### PDF-visuelle-Verifikation — **durchlaufen 2026-09-27** (Board-Position 10)
> Ziel: generierte Badge-/Ausweis-PDFs genauso visuell verifizieren wie UI-Screenshots (Vision-Agent gegen
> Checkliste: QR-Position, Feld-Überlappung, Abschneiden, Kontrast, Skalierung). Die SOLL-Beschreibung stand bis
> 2026-09-27 **ungetestet**; sie ist jetzt gemessen. Skript, Messwerte und Render-Vertrag:
> `features/badges-qr.md` → „Visuelle Verifikation des gerenderten PDF". Offen sind nur die zwei Punkte unten.

- [ ] **`background-color: #ffffff` auf `body`/`@page` im Badge-HTML — PRODUKTENTSCHEIDUNG, nicht umgesetzt.** Wäre
  die einfachere und robustere Lösung (der Seitenhintergrund wäre im PDF selbst weiss, die Nachbearbeitung
  entfiele). Bewusst nicht mitgenommen: es ändert den **Render-Vertrag** — jeder Ausweis bekäme einen gemalten,
  nicht mehr transparenten Hintergrund (relevant für Ausweisspiele auf Folie/Glas/Siebdruck) — und
  `BadgeRenderService::cardHtml` ist der Vertrag, gegen den die Tests prüfen. Die Entscheidung liegt beim Benutzer.
- [ ] **CI-Pfad (optional):** `poppler-utils` (`pdftoppm`) in `deployment/Dockerfile.e2e` aufnehmen, **falls**
  PDF-Vision jemals automatisierte Checks werden soll. Für die Verifikation von Hand nicht nötig —
  `scripts/pdf-to-png-vision.sh` deckt macOS (`magick`+`gs`, `gs`-only, `sips`) und ein Linux-Feld mit `gs` ab.
  Nicht blockierend.

**Was die Messung an den alten Annahmen korrigiert hat** (nicht nur bestätigt):

- ❌ „transparente Pixel erscheinen im PNG schwarz" — **für den `magick`-Pfad falsch.** Sie sind `#FFFFFF00`
  (weiss, alpha 0) und decken 89,8 % der Seite. Schwarz werden sie erst, wenn ein Konsument auf schwarzen Grund
  komponiert; dann verschwindet die schwarze Badgeschrift **vollständig** (0 sichtbare Tintepixel in einem
  79 520-Pixel-Textband). Für `sips` stimmt die Behauptung wörtlich (`#00000000`).
- ➕ Es gibt eine **einstufige** Route: `gs -sDEVICE=png16m` ist ein *deckendes* Device (kein Alpha, weisser
  Hintergrund von Ghostscript gemalt) — 0,29 % Abweichung zum Primärpfad, reines Anti-Aliasing-Rauschen. Das
  Skript nutzt sie als Fallback A, wenn `magick` fehlt.
- ⚠️ `magick identify -format '%[pixel:p{x,y}]'` meldet auf PaletteAlpha-Bildern **immer** `srgba(0,0,0,0)`, egal was
  wirklich dasteht — ein Befund mit diesem Werkzeug „beweist" einen schwarzen Hintergrund, den die Datei nicht
  enthält. Korrekt ist ein 1×1-Crop mit `txt:`.
- ⚠️ `sips` quittiert ein unlesbares PDF mit `not a valid file - skipping` und **Exit 0** — Skripte müssen die
  Zieldatei prüfen, nicht den Exit-Code.
- ➕ Der Layoutkasten **clippt nicht** (nur `photo`/`image` tragen `overflow:hidden`): gemessen ein 10-mm-Kasten
  mit 12,2 mm Tinte, 4,8 mm darüber hinaus. Eine Feld-Überlappung ist damit nicht automatisch ein Renderer-Fehler,
  sondern kann aus einem zu kleinen Kasten im Template kommen.
- ℹ️ Vision-Provider kann flaky sein → PNGs notfalls per Read-Tool selbst analysieren; die PNGs sind
  nachweislich self-contained (kein Alpha, weisser Grund).

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
- [x] **W12 — Venue-Stammdaten** — Backend + FE umgesetzt und verifiziert, **eine Position
  offen: E2E nie ausgeführt** (siehe Ende des Eintrags + „Nächster Batch" Position 1)
  (nach W5-Entscheidung: mandant-weite Liste, KEIN Geo, Suche nach Verein/Ort): `venues`-Tabelle + Admin-CRUD stehen (Backend, `venues.manage` an `mandant_admin` **und** `team_admin` — der Venue-Picker im Team-/Event-Formular darf für den Team-Admin nicht 403en, sonst dead-endet der von uns bestätigte Inline-Create). `events.venue_id` nullable **ersetzt** `events.venue` (Freitext-Spalte ist gedroppt — „ergänzend" wäre die superseded Variante, eine zweite Wahrheit ist genau das, was hier rausfällt). Offen: ~~FE (`VenueCombobox`/`VenuesPage`)~~ **erledigt** (`6f5c449`) — Combobox mit
Inline-Erstellung, `VenuesPage`/`VenueForm`, Team-/Event-Formular auf `venue_id`
umgestellt, 27 i18n-Keys DE+EN, 304 Vitest grün. 18 Backend-Consumer mitgezogen
(`a0511b6`), darunter `PortalController` + die Portal-Resources — Venue ist im
**Portal-Kalender** sichtbar, dort bleibt es beim Namens-String (Ids werden nie öffentlich).
**Offen bleibt genau eine Sache:** der **E2E-Spec `admin-venue.spec.ts` ist nie gelaufen** —
weder lokal noch in CI (beide Tests tragen nur `@feature:admin:venue`, `ci.yml:8` fährt auf
`push` ausschließlich `@smoke`). Das war Position 1 im „Nächster Batch" und ist mit dem vollen CI-Lauf erledigt; erst danach ist W12
wirklich abgeschlossen.
- [ ] **Venue-Schreibbreite für `team_admin` (PRODUCT-DECISION, 2026-09-27):** Ein
  `team_admin` darf heute **jeden** Ort seines Mandanten umbenennen, deaktivieren und
  löschen — auch einen, den ein Nachbarverein benutzt. Kategorien sind strenger: dort
  greift `assertOwnership` und ein Team-Admin darf nur *team-eigene* Zeilen
  anfassen, mandantweite sind für ihn read-only. Beides ist vertretbar, es ist aber eine
  **Fachentscheidung**: ein Verein, der einen Fremdort umbenennt, ärgert Nachbarn; ein
  Verein, der einen Ort nicht umbenennen darf, kann seinen eigenen nicht pflegen. Der
  Implementierer hat die breite Variante als korrekt *und* die Breite in
  `test_a_team_admin_manages_the_venue_list_of_his_whole_mandant` festgeschrieben, damit
  sie dokumentiert und nicht zufällig ist. Der Hebel für die engere Variante wäre ein
  `assertOwnership`-Äquivalent auf der Venue-Oberfläche. **Nicht selbst entscheiden.**
- [ ] **Dev-DB-Hinweis (W6-F3-Rest):** einmalig `migrate:fresh --seed` (sort_order-Schema).
- [ ] **Lernpunkt Parallel-Tests —URSACHE KORRIGIERT 2026-09-27** (die alte Begründung war
  falsch und perpetuierte einen behobenen Fehler): Die Formulierung „`Storage::fake` teilt
  `storage/framework/testing/disks/*` → Cross-Prozess-Race" ist **überholt** — `0f9cf57` hat
  die Kollisionsseite geschlossen (prozesseigener Storage-Root). Wer die Regel heute noch
  mit diesem Grund befolgt, diszipliniert eine Ursache, die es nicht mehr gibt.
  **Der aktuelle, reproduzierte Lernpunkt ist ein anderer:** Am 2026-09-27 liefen
  `php artisan test` und `pnpm test:run` **parallel** auf einer 18-Kern-Maschine mit
  Grundlast ~20. Der Vitest-Reserve-Test (`UsersPage`, bläht unter CPU-Überschreibung um
  3–5× auf) riss dadurch das Budget und schlug fehl — Last 24.78, Timeout 10000 ms
  überschritten. **Ursache: CPU-Überschreibung, nicht ein geteiltes Dateisystem.** Ich habe
  also die Regel gelesen und beim eigenen Verifizieren gebrochen.
  Für die Verifikation gilt künftig: **sequenziell, nie parallel** — und die Regel gehört
  nach `AGENTS.md` §7 (*Kandidat für dauerhafte Regel — nicht verschoben*).

### Reihenfolge
W1 → W2/W4 (disjunkt, parallel ok) → W3/W5 (Analyse) → W6 → W7 → W8 → W9.

---

## 🔍 Whole-Project Code Review — 2026-09-26 (abgeschlossen + verifiziert)

> **Methode:** 6 Review-Subagenten über den kompletten Stand (`1b66e00`, ~14.4k LOC
> Backend + ~12.8k LOC Frontend), danach 11 Umsetzungspakete + 4 Verifikations-/Fix-Wellen,
> jede Umsetzung mit separatem Verifikator (§5). Die kritischen Befunde wurden vom
> Build-Agent **nachimitiert** (CSRF-Kette, `putFileAs`-Reihenfolge, `throw=false`,
> Method-Override, NULL-Ordering, E2E-Assertion, zod-`path`, Spread-Reihenfolge) — drei
> Subagenten-Prämissen erwiesen sich als falsch und wurden korrigiert, nicht übernommen.
>
> **Alle Work-Pakete sind umgesetzt, §5-verifiziert und committet.** Abgeschlossene
> TODOs sind hier **vollständig entfernt** (§4), nicht abgehakt und nicht in einen
> „Erledigt"-Bereich verschoben. Die dauerhaft gültigen Entscheidungen stehen jetzt in
> `features/`:
> SameSite/CSRF/Proxy/Host-Allow-List → `auth/01-auth-and-roles.md` ·
> Quota-Atomarität/Widerruf-Kaskade/410 → `accreditation/01-allocation-engine.md` ·
> NULL-Ordering/`role_user`/SMTP-Verschlüsselung → `02-domain-model.md` ·
> Media-Invariante/Reaper → `media-domain-layout.md` + `04-media-self-service.md` ·
> Prod-Topologie/Upstream/Migrationen → `03-caddy-brand-files.md` · Token-v2 →
> `badges-qr.md` + `wallet-pkpass.md` · E2E-Test-Image → `05-e2e-test-image.md`.
>
> Commits: `47da372` auth · `f8e649c` QR-v2 · `10f1ecb`+`ac3ca70` media · `e577125`
> allocation · `8d1b106` schema · `9a7bed3` deploy+migrations · `2e80656` dockerignore ·
> `3d9d3d3` CI · `caf600b` frontend · `f1a2f08` test-isolation · `c0e821b`
> E2E-Image-Provenienz · `5363645` Mandanten-Mitgliedschaft · `32c7185`
> Nightly-Gate · `0f9cf57` prozess-eindeutiger Fake-Root.
> **Abschlussstand (2026-09-26, alle drei CI-Jobs grün):** Backend **1348 passed
> (6726 Assertions)** / Pint PASS 249 · Frontend lint+build sauber / **252** Vitest ·
> E2E-Smoke **17 passed / 9 skipped / 0 failed** · Compose: `config` exit 0 mit
> Vars, exit 1 mit `:?`-Meldung ohne `APP_KEY`/`DB_USERNAME`/`DB_PASSWORD`, alle
> Ports auf `127.0.0.1`.

### 🔴 Review-Finding #6 — JWT nicht mandanten-gebunden (geschlossen)
> Dieser Befund war in **keinem** der 11 Work-Pakete gelandet (Build-Agent-
> Eigenversäumnis, erst an der Abschlussprüfung wiedergefunden) — verifiziert,
> umgesetzt und **committet** (`5363645`).
> **Lücke:** `User::getJWTCustomClaims()` lieferte `[]` (kein Mandanten-Claim) und
> `mayLogInOnCurrentMandant()` galt nur beim Login ⇒ ungegatete `POST
> /accreditations/{id}/apply` scoped die *Akkreditierung*, nie die *Identität*; ein
> User des Verbandes A konnte sein Cookie mit `Host: b.example` erneut spielen ⇒
> Antrag **in Verband B**, Uploads in B's Media-Namespace, fremder Antrag in B's
> Freigabe-Liste.
> **Geschlossen:** `EnsureMandantMembership` in der Priority-List direkt nach
> `SubstituteBindings` ⇒ läuft als letzte Middleware vor dem Controller (nach
> `auth:api` und allen Route-Limitern, vor jeder Mutation). Regel: unter
> `auth:api` muss der User ≥1 `role_user`-Zeile für den aufgelösten Mandanten
> haben, sonst 403; globaler `super_admin` bleibt überall erlaubt.
> **Per-Request statt JWT-Claim** — begründet: ein Claim wäre unter Rollen-Entzug
> bis `JWT_TTL` (60 min) gültig, die Middleware wirkt sofort und schließt damit
> einen Teil der bekannten Lücke, dass `updateRoles` ausgestellte JWTs nicht
> invalidiert. Kosten: **1** Query (0,057 ms, Index Scan `role_user_scope_unique`),
> **0** auf allen öffentlichen Routen.
> **64 Vorbestehende Tests** wurden auf echte Mitglieder umgestellt (User +
> `role_user`-Zeile) — Fixture-Realismus, keine Abschwächung; der alte Zustand ist
> in Produktion nicht erreichbar.
> **Bewiesen:** 7 der 13 neuen Tests scheitern auf dem Pre-Fix-Code; E2E 17/0 mit
> und ohne Middleware-Register identisch.


### Offene Follow-ups

- [ ] **403/404-Split auf `{mandant}` (low, bewusst offen):** `User` war auf
  `PUT /api/admin/users/{user}/roles` ohne Mandant-Scope gebunden — das ist seit
  `a761f4a` **behoben** (`User::resolveRouteBindingQuery()` scoped über genau die
  `isMemberOfMandant()`-Prädikat, beide über `constrainToMandantMembership()`),
  weil dort PII (Name, E-Mail, Adresse) gemint wird. `Mandant` trägt dieselbe
  Form, **bleibt aber bewusst offen**, weil die naive Lösung falsch ist: ein
  `where('mandant_id', currentId)`-Scope entzieht `super_admin` den Zweck, jeden
  Mandanten über einen beliebigen Host zu verwalten (`PUT /api/admin/mandants/{B}`
  mit A als Host ist ein getesteter, gewollter Flow). Was dort durchsickert, ist
  „Mandant X existiert" — jeder Mandant hat ohnehin ein öffentliches Portal, sein
  Hostname steht in der öffentlichen `trustHosts`-Liste, und es sind eine
  Handvoll Zeilen. `MandantDomain` ist **überhaupt nicht** route-gebunden
  (`DELETE …/domains/{domain}` nimmt `string $domain` und sucht selbst über die
  Relation). Die richtige Form, falls es jemand will, ist eine **403/404-Unifikation
  im Controller** — wie `TeamController`/`UserController` sie für ihre Ressourcen
  schon haben — als eigenes Ticket mit eigenen Tests, nicht in einem Binding-Scope
  mitgeschmuggelt.