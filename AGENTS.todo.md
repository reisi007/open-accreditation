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

> **Stand 2026-09-27, Ende der Go-Live-freien Runde.** Die Umsetzungspositionen sind
> abgearbeitet und verifiziert (Lauf `36337776954`, alle vier Jobs grün) und wurden
> nach §4 **entfernt** — sie stehen nicht abgehakt hier. Was bleibt, sind **ausschließlich
> Entscheidungen, die dem Build-Agenten nicht zustehen**, plus das Go-Live.
> Jede neue Position ist §5-konform zu delegieren (Implementer ≠ Verifikator) und fordert
> Tests nach §3 DoD.

| # | Offene Position | Aufwand | Warum offen |
|---|---|---|---|
| **5** | **Ein *partieller* Re-Capture (`-g <route>`) löscht die übrigen Captures — §7 Schritt 4 ist auch für neu erfasste Routen unmöglich** (medium, 2026-09-28) | S1 | **Gleiche Fehlerklasse wie die gelöste Position 5, an der zweiten Stelle.** Gemessen: **95 → 4 PNG** nach einem Lauf mit `-g <route>`. Der Screenshot-Harness **leert sein eigenes Verzeichnis** vor jedem Lauf — und weil die Captures aller Routen **darin** liegen, nimmt ein gezielter Lauf alle anderen mit. **Warum das getrennt von Position 5 zählt:** Position 5 („E2E-Lauf löscht die Screenshots") ist behoben, indem die beiden Läufe **verschiedene** `outputDir` bekommen. Das hier ist **dieselbe Ursache im selben Harness** und braucht dieselbe Art Lösung — nur innerhalb des Screenshot-Laufs, zwischen vollem und partiellem Lauf. **Konkrete Folge für das Verfahren:** §7 Schritt 4 verlangt ausdrücklich „`vision`-Subagent vergleicht **old vs new**". Nach einem Re-Capture gibt es kein „old" mehr — **auch nicht für die Routen, die man gerade neu aufgenommen hat.** Der Fix-Loop des Design-QA-Verfahrens ist damit in **beiden** Richtungen blockiert. **Braucht eine Design-Entscheidung**, die über die drei beauftragten Positionen hinausgeht: z. B. einen Zeitstempel-Sibling-Baum (`ui-screenshots/<run>/…`) statt eines flachen Verzeichnisses, oder ein Overlay, das vorhandene Dateien **nicht** löscht. **Bewusst nicht entschieden**, weil die Antwort die Verzeichnisstruktur des Harness ändert und damit jeden bestehenden Screenshot-Pfad. |
| **6** | **Die Screenshot-Seeds leaken — drei identische Läufe ergeben 35 / 45 / 66 Sections** (medium, 2026-09-28) | S1 | **Befund, der erst durch die Section-Captures sichtbar wurde** — vorher hat niemand hingesehen, weil es nur 60 Full-Page-Bilder gab, deren Zahl **konstant** war. Gemessen über drei identische Läufe: die **Seitenhöhen wachsen** (`home`/mobile **3586 → 3814 → 5112** CSS px), weil die Seeds **nicht aufräumen**: die DB hält **210 User** und **14 Badge-Templates**, `ensurePrimaryMandantAccreditation()` legt **pro Aufruf eine Kategorie** an. **Damit ist der Review-Batch über Läufe hinweg nicht stabil** — und die Section-Anzahl datenabhängig, also **keine feste Batch-Größe** vorausplanbar. **Zwei Konsequenzen, beide relevant:** (a) Der **erste** Vision-Loop dieser Sitzung lief gegen eine DB mit 210 Usern und 14 Templates — die „filled"-Zustände waren **reicher als beabsichtigt** und **nicht reproduzierbar**; ein „APPROVED" aus diesem Lauf gilt für **diesen** Datenstand, nicht für einen frischen. (b) Dasselbe Phänomen erklärt den **nicht reproduzierbaren** `a11y`-Fehlschlag: die **Zeilenzahl** der Kategorien entscheidet, wie viele Tab-Drücke der Test braucht — also hing der Fehlschlag an der **Reihenfolge**, in der andere Tests seeds abgesetzt hatten, und nicht an Timing. **Fixrichtung:** die Seeds vor dem Capture in einen **bekannten** Zustand setzen (Purge wie beim E2E-Hygiene) und die Bandzahl **protokollieren**, damit ein Batch nachvollziehbar bleibt. |
| **7** | **Nightly `@regression` einmal fahren** | S1 | Der Cron hat **null** Läufe; der Flakiness-Detektor hat noch nie gegen diesen Stand gearbeitet. Ein Lauf kann rot werden — das ist Information, kein Fehler. |
| **8** | **Der Vision-Loop prüft den Editor, nicht das gerenderte PDF — dompdf-Fehler sind dort prinzipiell unsichtbar** (medium, 2026-09-28) | S1 | **Entschieden 2026-09-28: das gerenderte Ausweis-PDF kommt in den Loop.** Die Lücke ist **strukturell**, nicht sporadisch, und die Kette ist gemessen: `BadgeExportService:80` rendert **eine A6-Karte pro Antrag** in einem PDF und hat **keine** Postcondition im Backend (`grep` nach `pdf-to-png`/`has_alpha`/`corner_alpha` in `backend/app/`: **null** Treffer). Der Vision-Loop screenshotet den **Editor** — HTML/CSS, Browser. `pdf-to-png-vision.sh` prüft **ein** PDF, aber **nur wenn jemand es von Hand aufruft**. **Dazwischen liegt die Lücke:** ein Template kann im Editor **perfekt** aussehen und trotzdem 500 Ausweise **falsch** drucken. **Belegt, nicht behauptet:** genau das ist heute **zweimal** passiert — die `object-fit`-Streckung und die QR-Verzerrung waren **beide** im Editor unsichtbar, weil der Browser `object-fit` beherrscht und dompdf nicht. **Ein Editor-Screenshot kann einen dompdf-Fehler prinzipiell nicht zeigen.** **Vertrag für die Umsetzung:** (a) die Screenshot-Spec holt das PDF über den **echten** Export-Weg (`GET /api/admin/accreditations/{id}/badges/export` mit Session-Cookie) — nicht über einen Renderer-Aufruf im Test, sonst prüft der Loop etwas anderes als die Produktion; (b) rastert es mit `scripts/pdf-to-png-vision.sh` (dessen Postcondition inzwischen auf **Opazität** prüft); (c) legt die resultierenden PNGs **neben** den Editor-Screenshots ab, damit beide in **einem** Batch beim `vision`-Subagenten liegen; (d) der Auftrag an den Vision-Agenten sagt ausdrücklich, dass er **zwei Ansichten derselben Vorlage** vergleicht — Editor gegen Druck. **Warum (a) so wichtig ist:** ein im Test direkt aufgerufener `renderPdf()` wäre ein **anderer** Pfad als der Export, und genau der Unterschied, den man prüfen will, wäre ausgeschlossen. **Grenze, die nicht zu schönreden ist:** der Loop prüft **eine** Vorlage, der Export **viele** — er findet Klassen von Fehlern, keine Einzelfälle im Massenlauf. Für den Rest bleibt Position 7 (Schwarzseite) die offene Frage. |
| **9** | **Druck: `name` läuft aus seiner Box und überlappt `category` — Überlauf muss umbrechen, nicht abschneiden** (medium, 2026-09-28) | S1 | **NUTZERENTSCHEIDUNG 2026-09-28: „nie abschneiden, muss dann mit Zeilenumbruch umgehen können ohne das ganze Layout zu brechen."** **Gemessen:** 14 pt in 40 × 8 mm, zweizeilig, im **Druck** — im Editor ist es unauffällig. **Die Ursache ist eine dokumentierte Asymmetrie, kein Zufall:** `BadgeCanvas.tsx:154` → `badgeCanvasFontSizeCss` kappt die Schrift per `min()` gegen **eine** Ein-Zeilen-Breite (`cqw`) **und** eine **zweizeilen-sichere** Höhe (`cqh`), Boden 2 px (FE4-F1, genau dafür gebaut). `BadgeRenderService::renderField:394` setzt `font-size:%dpt` **wörtlich** aus dem Template, einzige Anpassung `max(1, …)` — **kein Auto-Fit**. **Wichtig, und es begründet die Entscheidung:** der Editor kann das **nicht** ehrlich machen — eine Vorlage hat **keine Person**, er zeigt `SAMPLE_TEXT.name = „Max Mustermann"`. Er ist strukturell **optimistisch**. **Also gehört die Robustheit in den Druck**, und zwar als **derselbe Vertrag**: eine Zeile, sonst Umbruch, und die Schrift so klein, dass der **umbrochene** Text in die Box passt. **Die Umsetzung ist nicht CSS:** dompdf kennt keine Container-Einheiten, also misst das Backend die Textbreite **selbst** (Font-Metriken). **Nicht** `overflow:hidden` + Ellipse — das ist abgelehnt, weil ein abgeschnittener Name auf einem Ausweis die Person **nicht eindeutig** zuordnet, und gedruckt ist gedruckt. **Rest, der nicht wegdefiniert werden kann:** unterhalb der Untergrenze passt auch der umbrochene Text nicht in 8 mm, und ein `position:absolute`-Feld kann dann nur noch **überlappen**. Das ist eine **Grenze der Geometrie**, keine offene Frage — sie wird gemessen und benannt, nicht weggetestet. |
| **10** | **Konto-Löschung: Nutzer selbst, `mandant_admin` und `super_admin` — und es wird **wirklich** gelöscht** (medium, 2026-09-28) | S1 | **NUTZERENTSCHEIDE 2026-09-28, alle drei beantwortet.** (1) *Selbstbedienung:* „user soll selbst account löschen können, admins bzw. super admins dürfen auch accounts löschen." (2) *Harte Löschung:* „wirklich löschen wegen DSGVO" — **keine** Anonymisierung. (3) *Mandantenübergreifend:* **ja**, ein `super_admin` darf Benutzer **anderer** Mandanten löschen, **wie bei jeder anderen Berechtigung** — `Gate::before` (`AuthServiceProvider:28`) gibt ihm bereits **jeden** Gate mandantenunabhängig frei, ein `users.delete`-Gate hätte für ihn also **nichts** geändert; eine Sonderregel nur für die Löschung wäre willkürlich. **Pflicht dabei:** eine **explizite Rückfrage**, die den **Namen des Kontos** und die **Anzahl seiner Anträge** nennt. **Das Schema hat die Kaskade bereits entschieden** (meine Frage im Board war die falsch formulierte): `role_user` · `media` · `applications` · `sub_applications` → alle vier `cascadeOnDelete()`. **Einzige echte Lücke:** `sessions.user_id` ist `nullable()->index()` **ohne** FK — die Zeile bliebe verwaist. **Jeder gedruckte Ausweis hängt an einem Antrag**; mit dem Antrag stirbt sein `qr_token`, der Ausweis ist **nicht mehr verifizierbar**. **Das ist die richtige DSGVO-Folge** — der Token wäre sonst ein Weg, die gelöschte Person wiederzuerkennen — und es muss dem Nutzer **gesagt** werden, nicht verschwiegen. **Protokollierung:** Nutzerentscheid „kein Audit, nur das Anwendungslog" → akzeptiertes Risiko **A5** in `AGENTS.md` §10. **Die JWT-Frage ist GEMESSEN und die Zusage trägt:** die Widerruf-Wirkung hängt **nicht** an der Blacklist, sondern am **DB-Treffer** — `JWTGuard::user():107` ruft `retrieveById($payload[‚sub‘])`, fehlt die Zeile, ist `$this->user` null → **401 sofort**, nicht „60 Minuten Zugang". **Achtung, zwei verschiedene Wege, sehr verschiedene Güte:** (a) **Konto gelöscht** = DB-Zeile weg = **sofort 401**, trägt; (b) **`logout()`** = Token in die **Cache**-Blacklist (`Providers\Storage\Illuminate::__construct(CacheContract $cache)` — **Cache**, keine Tabelle) und dort `JWTGuard::logout():219-223` `catch (JWTException) {}` → **200 „Erfolgreich abgemailed", obwohl nichts gesperrt wurde** (Vendor-Verhalten, akzeptiertes Risiko, **nicht** im Repo gefixt). **Die Löschung darf Weg (a) nie über Weg (b) begründen** — das ist jetzt als Vertragstest festgeschrieben.

**Umsetzungsvertrag, aus dem Code gelesen (nicht entworfen):**
- **Neue Berechtigung `users.delete`**, getrennt von `users.manage` (`config/permissions.php:77` — die existiert bereits und ist **Rollenvergabe**). Löschung ans `users.manage` zu hängen hiesse: wer Rollen verteilen darf, darf auch Konten beenden. `mandant_admin` bekommt `users.delete`; `super_admin` hat `'*'` und umgeht es ohnehin.
- **Zwei Routen, zwei Autorisierungen:** `DELETE /api/user/account` unter `auth:api`, **ohne** Gate — das Ziel ist `$request->user()`, ein Gate wäre nur eine zweite Fehlerquelle. `DELETE /api/admin/users/{user}` mit `can:users.delete`, mandantenscoped. `user` hält heute nur `accreditations.self` (`:100`), die Selbstbedienung braucht also **keine** neue Rolle.
- **Die Kaskade löscht die Zeile, nicht die Datei.** `UserMediaService` hat **keine** `destroy()` — nur `store()`, und dessen Docblock beschreibt „unlink the predecessor files after the commit" nur für den **Vorher**-Fall. Ein gelöschtes Konto nähme sein **Porträt** also als Datei mit. `MandantMediaService::destroy()` macht es richtig (`:212`), und `MandantController` hat dafür `purgeWithRetry` (`:344`) samt Vertrag: unlink schlägt fehl → **loggen, weiterlaufen, Reste melden**, nicht 500 werfen. **Derselbe Vertrag gehört hierher**, und `UserMediaService::destroy()` ist Teil des Auftrags, nicht Nebenarbeit.
- **`sessions.user_id`** (kein FK) wird **explizit** mitgelöscht, sonst bleibt die Zeile verwaist.
- **Selbstbedienung hat keine Oberfläche.** Das Frontend ruft `PUT /api/user/profile` **nirgends** auf — es gibt **keine** Profilseite, nur `MyAccreditationsPage` und `PortalHomePage`. Und `UsersPage.tsx` hat **keine** destruktive Aktion. „Nutzer kann selbst löschen" ist also **kein Button**, sondern eine Selbstbedienungsfläche von Grund auf — das ist Umfang, der in der Entscheidung „Selbstbedienung" nicht sichtbar war, und er gehört benannt statt stillschweigend mitgenommen. |
| **11** | **`ui-review-captures.mjs` nennt den Store-Pfad als **eigenes** Konstant — nichts erzwingt, dass beide übereinstimmen** (low, 2026-09-28) | S1 | **Dieselbe Fehlerklasse wie `features/badges-qr.md` vs. §7 vs. der Code — und ich habe heute drei Regeln dagegen geschrieben.** `frontend/scripts/ui-review-captures.mjs` ist reines ESM und muss standalone lauffähig bleiben, also **kann** es das TS-Modul ohne Build-Schritt nicht importieren. Der Agent hat deshalb einen **Kommentar** mit der Quelle der Wahrheit hinterlegt — **ein Kommentar erzwingt nichts.** **Der Fehler ist damit latent und still:** steht der Pfad in der einen Datei falsch, meldet der Sammelbericht „0 Bänder" oder einen leeren Lauf, während die Bilder **da** sind. **Gehört behoben, weil der Bericht das Werkzeug ist, mit dem ein Review-Batch überprüfbar wird** — ein Sammelbericht, der den falschen Ordner sucht, entwertet genau den Nachweis, den §7 verlangt. **Lösung ist strukturell, nicht kosmetisch:** der Pfad darf **nur einmal** im Repo stehen (Konstante in einem gemeinsamen Modul), und ein **Test** vergleicht die vom Skript gelesene Konstante mit der des TS-Moduls. **Ein Test, nicht ein Kommentar** — das ist der Unterschied, den diese Sitzung teuer gelehrt hat. |
| **12** | **Ein Checkout mit einem Store am alten Pfad (`test-results/ui-review/`) wird weder umgezogen noch gewarnt** (low, 2026-09-28) | S1 | Der Store ist per `mv` **von Hand** umgezogen (einmalig, in einem gitignorierten Verzeichnis). **Folge:** jeder Checkout, der noch einen Store von einem älteren Commit trägt, behält eine **veraltete Kopie** am alten Pfad — und **nichts** warnt, **nichts** migriert, **nichts** räumt sie ab. Wer danach `test-artifacts/ui-review/` nicht kennt und den alten Pfad öffnet, sieht einen **vollständigen, plausibel aussehenden, aber veralteten** Review-Batch. **Das ist die gefährlichste Form von Altlast**: nicht ein Fehler, sondern ein **richtig aussehender falscher** Befund. **Gehört behoben** — und zwar **nicht** durch Aufräumen (ein gitignorierter Ordner ist vergessen, sobald niemand mehr hinschaut), sondern durch einen **Hinweis mit Ausstieg**: meldet der Harness einen nichtleeren alten Pfad, mit dem aktuellen als Vergleich. Billig, und es verhindert, dass jemand einen Batch prüft, der nicht existiert. |
| **13** | **`withCookie()` transportiert im API-Test **nichts** — Auth-Tests sind aus dem falschen Grund grün** (high, 2026-09-28) | S1 | **Gefunden, als ein Agent eine eigene Messung als Falsch-Positiv entlarfte.** `MakesHttpRequests::prepareCookiesForJsonRequest()` (`:747-750`) ist **eine Zeile**: `return $this->withCredentials ? $this->prepareCookiesForRequest() : [];` — **ohne** `withCredentials()` werden Cookies **stillschweigend verworfen**; **mit** `withCredentials()` verschlüsselt `withCookie()`, und die `api`-Gruppe hat **kein** `EncryptCookies` (`bootstrap/app.php:23+` konfiguriert nur die Host-Allow-List), also entschlüsselt niemand. **Folge:** jeder Request ohne Token sollte **401** sein — und ist es nicht, weil der `JWT::$token`-**Singleton** aus `login()` noch gesetzt ist. **Das ist dieselbe Fehlerform wie der Falsch-Positiv, aus dem er entstanden ist: grün, weil ein Zustand nebenbei gesetzt war, nicht weil die geprüfte Sache funktioniert.** **Was dadurch als Beweis hinfällig ist:** `AuthLoginTest.php:105-114` transportiert das JWT-Cookie per `withCookie()` und prüft dann `assertOk()` — diese drei Assertions prüfen **nicht** den Cookie-Pfad. `AGENTS.md` §11 führt „httpOnly-Cookie-Auth" als **bestätigte Stärke**; dieser Beleg trägt die Aussage **nicht**. (Die Produktion ist unberührt — der Browser sendet den Cookie. Betroffen ist die **Evidenz**.) **Deckt sich mit dem 30-Minuten-Stall:** dieselbe Klasse, anderes Symptom — ein Test, der aus einem Zustand grün ist, den er selbst erzeugt hat. **UMGESETZT 2026-09-28, wartet auf den separaten Verifikator.** Kanal: `TestCase::withJwtCookie()` — `withCredentials()` (schaltet den JSON-Cookie-Transport überhaupt erst ein) **plus** `withUnencryptedCookie()` (Klartext, weil `EncryptCookies` auf der `api`-Gruppe nie läuft); beides privat in dieser einen Methode. **Kanal-Test:** `JwtCookieChannelTest` (8 Tests, 28 Assertions) mit der gemessenen Tabelle `withCookie()` → 401 · `withCookie()`+`withCredentials()` → 401 (Ciphertext `eyJpdiI6` = `{"iv":` statt `eyJ0eXAi` = `{"typ":`) · `withUnencryptedCookie()` allein → 401 · `withJwtCookie()` → 200 · `->call()` → 401. **Rückfallwächter:** `ForbiddenJwtCookieChannelTest` (5/22) verbietet `withCookie(`, `withCredentials(`, `->call(` in ausführbarem Code unter `tests/` — Kommentare vorher via `token_get_all` entfernt, damit die Prosa, die den Bug erklärt, nicht anschlägt; die Ausnahme ist eine **festgenagelte Anzahl** (`EXEMPT`), **kein Pfad**, weil eine Pfadausnahme ein Loch mit Kommentar ist. **Mutationen:** neutralisierter Kanal → 4 rot · verschlüsselter Kanal → 5 rot · je injizierte verbotene Schreibweise → 1 rot.

**Der Fund, der den Fund auslöst — 18 Tests prüften die Tür, nicht das Schloss.** Nach der Reparatur: `1160 passed` vorher **und** nachher, **und 18 wurden dabei rot.** Sie behaupteten `403` und bekamen `401`. **403 = authentifiziert, aber verboten; 401 = nicht authentifiziert** — eine 403-Zusage auf einer nie authentifizierten Anfrage kann ein funktionierendes Gate nicht von einer **zugemauerten Tür** unterscheiden. Betroffen: `AdminMandantTest:96` (5 Admin-Routen × 5 Rollen) und `MandantMediaSelfServiceTest:252/260/281` (6 Self-Service-Routen × 2 Rollen + Fremdmandant). **Ausgelöst durch `actingAsApi()`**, das den `JWT::$token`-Singleton stehen ließ — die Reparatur der einen Tatsache deckte 18 falsche Zusagen auf, die **nie geprüft** waren. **Ein vierter kaputter Kanal kam erst beim Messen dazu:** `MakesHttpRequests::call()` nimmt Cookies als **dritten** Parameter und **defaultet auf `[]`**, ein direkter `->call($method, $uri)` transportiert also **gar nichts**, egal wie die Konfiguration aussieht — und genau darüber liefen die 18. |
| **14** | **`Google Wallet` (P6)** | — | Externer Schritt: API-Zugang/Issuer-Setup bei Google. Von uns nicht leistbar. |


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
| D19 | Badge-Editor-Interaktion (2026-09-27) | **Raster + konfigurierbare Labels** — kein Maus-Drag, keine Eckgriffe, keine magnetischen Guides. Raster bleibt **5 mm** (ohne Toggle). Der **Pfeiltasten-Nudge kommt zurück**: er ist keine Zieh-Interaktion, Tastaturbedienung war nie ausgeschlossen, und ohne ihn ist relative Feinverstellung („1 mm nach links“ bei 27,4 mm) unmöglich. Konfiguration läuft über `BadgePropertiesPanel`. |
| D20 | Venue-Schreibbreite `team_admin` (2026-09-27) | **Lesen und Anlegen mandantweit, Ändern nur eigene Orte.** Lesen/Anlegen muss mandantweit bleiben, weil der bestätigte Inline-Create im Team-Formular sonst dead-endet und `venues.manage` zeilengleich `categories.manage` folgt. **Ändern** (Umbenennen, Deaktivieren, Löschen) wird getrennt und folgt dem Kategorie-Muster: ein Verein darf den Ort eines Nachbarvereins nicht umbenennen, ohne ihn zu fragen. Gilt für **beide** Flächen (host-skaliert **und** mandant-adressiert). |
| D21 | Multi-Domain-Admin-UX (2026-09-27) | **Host-relativ bleibt der Default, plus Dropdown zum Domainwechsel** für `super_admin`. Ausdrücklich **keine** Mandant-Parametrisierung aller Admin-Routen (die wäre die große Variante und ist nicht beschlossen). Der stille Cross-Mandant-Write ist mit `a2c8e5f` unabhängig davon behoben. |
| D22 | Badge-Hintergrund (2026-09-27) | **OFFEN.** `background-color:#ffffff` auf `body`/`@page` wäre technisch der robustere Fix — dann entfiele die Nachbearbeitung komplett. Nimmt den Ausweisen aber den **transparenten** Hintergrund, der für Ausweisspiele auf Folie, Glas und im Siebdruck relevant ist. Produktfrage, nicht technische; die Alpha-Entfernung ist bis dahin Sache des Skripts `scripts/pdf-to-png-vision.sh`. |

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