# 01 — Multi-Tenancy (Mandanten)

## SOLL

- **Mandant = Verband mit eigener Domain** (eigener Host-Header). Jeder Request
  wird über den Host einem Mandanten zugeordnet.
- **Kein Theme-System** (anders als Portal): pro Mandant nur Logo
  (`logo_path`), Header-Bild (`header_path`) und Legal-Texte
  (`impressum_text`, `privacy_text`). SMTP je Mandant (`smtp_config`, P5).
- **Team = Verein** (optional): je Mandant über `mandants.teams_enabled`
  freischaltbar. **Kategorien erben vom Mandant**, ein Team kann sie
  überschreiben (z. B. andere Quota, andere Frist) — Teams und
  Kategorie-Overrides kommen mit **P2**.
- **Personen-Konten pro Mandant**: Ein Konto gehört genau einem Mandanten
  (eigene Domain) — kein Cross-Mandant-Login (403 beim Login auf fremder
  Domain, siehe `features/auth/01-auth-and-roles.md`).

## Kategorie-Override & `is_team_override` (Ist P2/P3, P2b-F5)

Kategorien liegen mandant-scoped; eine Team-Kategorie überschreibt die
Verbands-Kategorie desselben `slug` (z. B. andere Quota/Frist). Der Flag im
Admin-API ist dabei rein **abgeleitet**, kein DB-Feld:

- `CategoryResource` (Admin-API): **`is_team_override` = `team_id !== null`**
  (`backend/app/Http/Resources/CategoryResource.php`). Er markiert jede
  Kategorie-Zeile auf **Team-Ebene** — unabhängig davon, ob fachlich ein
  Verbands-Datensatz mit gleichem Slug tatsächlich „überschrieben" wird.
- **UI-Folge (bewusst akzeptiert):** Das „Team-Override"-Badge
  (`frontend/src/pages/admin/CategoriesPage.tsx`, `badge-warning`) erscheint
  auf **jeder** Team-Kategorie — auch wenn es streng genommen nur ein
  „Team-Level"-Flag ist. Semantik-Kosmetik, kein Schema-/API-Change nötig;
  der Flag darf nicht als Nachweis eines echten Slug-Overrides missdeutet
  werden. Eine zweite, davon unabhängige bewusst akzeptierte UI-Approximation
  im gleichen Formular-Umfeld: die Multi-Domain-Admin-UX-Limitation
  (siehe unten).

## Host-Resolution (Ist P1)

- Auflösung über **`mandant_domains.hostname`** (DB): `MandantContext::resolve()`
  liest `host → mandant_id` via **Cache** (`mandant.domain.{host}`, TTL
  `config('mandants.cache_ttl')`, Default 3600 s). Gecacht wird nur die
  Host→ID-Mapping, die Mandant-Zeile selbst wird immer frisch aus der DB
  gelesen. Unbekannte Hosts werden **nicht** gecacht.
- `MandantContext::current()` hält den Mandanten im Container
  (`mandant.context`); `default()` liefert den Primary-Mandant
  (`is_primary = true`), Fallback `config('mandants.fallback_mandant')`.
- **`MandantContextMiddleware`** (`backend/app/Http/Middleware/MandantContextMiddleware.php`):
  - **`/up`-Short-Circuit:** Health-Endpoint braucht keinen Mandanten.
  - **Unbekannter Host → 404 in Prod.** Ausnahme: Console/Testing laufen ohne
    Mandant weiter (Tests setzen ihn manuell via `MandantContext::set()`).
  - **Loopback-Fallback (nur `local`/`testing`):** `localhost`/`127.0.0.1`/`::1`
    → Primary-Mandant statt 404 (Dev-Server, CI-Probes).
  - **Referer-Fallback (nur `local`):** Der Vite-Proxy überschreibt den
    Host-Header; der Referer trägt noch den Original-Host und wird bevorzugt
    (mirrors Portal `BrandContextMiddleware`).
- **Konfiguration** `backend/config/mandants.php`: `cache_ttl`,
  `fallback_mandant`, `defaults` (`teams_enabled`, `is_active` für P2-Admin-UI).
  Die Mandanten selbst liegen in der DB (Migrationen
  `create_mandants_tables`), nicht im Config.
- Mandant-Isolation: Nur `MandantDomain` nutzt den `scopeForCurrentMandant()`-
  Scope (host-abgeleitet, Portal-Muster). Mandant-scoped Rollen-/Domain-Queries
  laufen über explizite `scopeForMandant()`/`scopeForTeam()` (siehe `RoleUser`)
  — Cross-Mandant-Lecks sind damit ausgeschlossen, die Isolationsgarantie darf
  nicht regredieren.

## Multi-Domain-Admin-UX (Ist P2c, P2c-F4)

Die Admin-Oberfläche skaliert ihre Listen auf **zwei** Arten. Die
Unterscheidung ist der eigentlich interessante Punkt an dieser Stelle:

- **Host-skaliert** (der Standard): `/admin/categories`, `/admin/events`,
  `/admin/accreditations`, `/admin/users`. Diese Routen tragen **keine**
  Mandant-ID in der URL — es gibt also nichts, was das SPA durchreichen
  könnte. Das Backend löst über `MandantContext::currentId()` auf. Ein
  `super_admin` hat keinen mandant-scoped Rollenkontext (`mandant_id = NULL`,
  global); für ihn trägt `/me` das **host-abgeleitete** `current_mandant_id`,
  und genau das nutzt `useAdminTeams()`
  (`frontend/src/logic/useAdminTeams.ts:40-42`) als Quelle der Team-Listen
  und Team-Auswahlen (Kategorien, Events, Rollen-, Akkreditierungs-Formulare).
  Auf einer **Nicht-Primär-Domain** sind das damit die Teams des über die
  Domain adressierten Mandanten — nicht die des primären Mandanten. Die
  frühere Approximation über den primären Mandanten ist mit `2e35df1`
  (P2c-F4) entfernt; das Szenario ist regressionsgesichert in
  `useAdminTeams.test.tsx:107-127` und `AdminTeamTest.php:426-448`.
- **URL-skaliert**: die Mandant-CRUD-Oberfläche. `/admin/mandants/{mandant}/teams`
  ist eine Route mit `{mandant}`-Parameter und adressiert den Mandanten
  explizit; sie ist vom Host-Kontext **unabhängig** — ein `super_admin` darf
  jeden Mandanten von jedem Host aus verwalten
  (`TeamController::assertMandantRouteParameter()`, Super-Admin-Carve-out).

Weil Team-Dropdowns und ihre Listen beide host-skaliert auflösen, sind sie
untereinander **konsistent**: kein Falsch-Mandant in der Auswahl. Die alte
Fehlschaltung (Teams des primären Mandanten auf einer Nicht-Primär-Domain)
existiert nicht mehr.

### Offene Limitation: Venue-Auswahl auf der Mandant-Detail-Seite

Hier **kreuzen** sich die beiden Modelle. `MandantDetailPage` lädt die Teams
über den **URL**-Mandanten (`MandantDetailPage.tsx:48-51`), die
Heimstätte-Auswahl aber über `useVenues()` → `GET /api/admin/venues` — und
das ist **host-skaliert**: `VenueController` hat anders als `TeamController`
**keinen** `{mandant}`-Route-Parameter, `index` filtert
`forMandant($this->currentMandantId())` und `store` setzt
`'mandant_id' => $this->currentMandantId()`.

**Folge bei abweichendem Host-Mandanten:** Die Heimstätte-Auswahl bietet die
Orte des **Host**-Mandanten an, und ein **Inline-Create**
(`VenueCombobox` → `POST /api/admin/venues`) schreibt still in den
Host-Mandanten; der anschließende Team-Save auf denselben `venue_id`
scheitert erst dann mit 404 (`TeamController::assertVenueOfMandant()`). Das
ist ein **Datenintegritätsproblem** — die Fremd-venue existiert danach real
im Host-Mandanten —, kein reiner Anzeigefehler.

**Lösungspfad:** Es fehlt ein **mandant-adressierbarer Venue-Endpunkt**
(`/api/admin/mandants/{id}/venues`), analog zur Team-Route; Venue und Team
können dann dieselbe `{mandant}`-Quelle nutzen.

**Keine Isolationslücke, aber eine Invariante mit zwei Skalen:** Jeder Write
wird gegen **den Mandanten validiert, auf den seine Route skaliert** —
host-abgeleitet (`ResolvesAdminTeamScope::assertTeamOfMandant()`) auf den
host-skalierten Admin-Oberflächen, gegen den **URL**-`{mandant}` auf der
Mandant-CRUD-Oberfläche. Cross-Mandant-IDs antworten in beiden Fällen mit
404, ein falscher Kontext führt also nie zu einem Team, Event, einer
Akkreditierung oder einem Konto im falschen Mandanten. Für `Venue` gilt das
gerade **nicht** — siehe oben.

## Domainwechsel-Dropdown für `super_admin` (SOLL, D21)

**Rahmen.** Ein `super_admin` arbeitet **host-relativ** — das bleibt der Default
und ändert sich hier nicht. Zusätzlich bekommt er im Admin-Header ein
**Dropdown, zwischen den Domains der Mandanten wechseln**. Realisiert wird
ausschließlich die **Navigation**: jeder Wechsel ist eine Top-Level-Navigation
auf eine andere Origin, und der Server löst den neuen Mandanten ganz normal
über den Host-Header auf. Es gibt **kein** serverseitiges Umschalten, **kein**
`?mandant=`/`X-Mandant-Id`, **keine** Session-Persistenz des Kontexts, **keine**
Mandant-Parametrisierung der host-skalierten Admin-Routen.

**Warum das die host-relative Entscheidung nicht aufweicht:** Die
Dropdown-Zeile ist ein **Link auf eine fremde Origin**, keine Anweisung an die
laufende Anwendung. Der Host ist damit die einzige Wahrheit — genau wie auf der
Zieldomain ohnehin. Wer später doch mandant-parametrisierte Admin-Routen will,
findet diese Spec als ausdrücklich **verworfene Alternative** (unten) und muss
die Begründung dort widerlegen, nicht umgehen.

### Ist-Zustand (gemessen 2026-09-27, `main`)

| Frage | Antwort | Beleg |
|---|---|---|
| Wie kommt der Admin an alle Mandanten? | `GET /api/admin/mandants` in der `can:mandants.manage`-Gruppe. `index()` lädt **alle** Mandanten (kein `active()`-Filter) mit `with('domains')` + `withCount('teams')`, sortiert nach `name`. | `backend/routes/api.php:188-189`; `backend/app/Http/Controllers/Api/Admin/MandantController.php:76-85` |
| **Enthält die Ressource die Domains?** | **Ja.** `MandantResource` serialisiert `domains` als `[{id, hostname}]` (lazy nachgeladen, falls die Relation nicht eager geladen ist), außerdem `is_primary`, `is_active`, `slug`, `teams_count`. | `backend/app/Http/Resources/MandantResource.php:32-47` (`domains` → :45), `domainsList()` :112-123; `frontend/src/api/types.ts:29-49` |
| Wie erfährt der Store den aktuellen Mandanten? | `/api/auth/me` → `UserResource.current_mandant_id = MandantContext::currentId()` (host-abgeleitet, `null` ohne Kontext). Im Frontend über `useAuth()` (`useSWR('session')`) verfügbar. | `backend/routes/api.php:98`; `backend/app/Http/Resources/UserResource.php:47`; `frontend/src/logic/useAuth.ts:20-22`; `frontend/src/api/types.ts:9-19`; einziger aktueller Verbraucher: `frontend/src/logic/useAdminTeams.ts:40-42` |
| Wo käme das Dropdown hin? | `AdminLayout` rendert einen `<header className="navbar">` mit `navbar-start` (Logo-Link) und `navbar-end` (Menü-Button, E-Mail, Abmelden, `LanguageSwitcher`). `AdminNav` ist eine **lokal definierte Komponente derselben Datei** und wird **zweimal** instanziiert (Desktop-`aside` + Mobile-Drawer-`aside`). | `frontend/src/pages/admin/AdminLayout.tsx:117` (`AdminLayout`), Header :206-237, `navbar-end` :213-236, `LanguageSwitcher` :235; `AdminNav` :18-113, zweimal :244-253 und :267-276 |
| Wie ist der Host im Frontend repräsentiert? | **Gar nicht — und das ist korrekt.** Alle API-Calls sind **relative** Pfade (`fetch(path, …, {credentials:'include'})`); es gibt kein `VITE_API_*`, kein `import.meta.env` in `src/`, kein Config-Flag für den Host. Die einzige `window.location`-Nutzung im ganzen `src/` ist `new URL(photo_url, window.location.origin)` in der VerifyPage. Die **Window-Origin ist die einzige Wahrheit** — SPA und API teilen sich eine Origin. | `frontend/src/api/client.ts:56-65`; `frontend/vite.config.ts:9-26` (Proxy mit `changeOrigin: true`, kein Bundle-Env); `frontend/src/pages/VerifyPage.tsx:82` |
| Wie löst der Server den Host auf, und was bei mehreren Mandanten pro Host? | `MandantContext::resolve($host)` → Cache `mandant.domain.{host}` → `MandantDomain::where('hostname',$host)->value('mandant_id')` → `Mandant::active()->find($id)`. Die Middleware setzt das in den Container; unbekannter Host → 404 (außer Console/Testing). | `backend/app/Support/MandantContext.php:103-134` (:123 das `->value()`); `backend/app/Http/Middleware/MandantContextMiddleware.php:39-68`, `resolveHost()` :83-99, `isLoopback()` :125-135 |

**Konsequenz für Frage 4 (die critical one): Es ist _keine_ API-Erweiterung
nötig.** Die Daten (`name`, `hostname`, `is_active`, `is_primary`) liegen bereits
in `GET /api/admin/mandants`; die aktuelle Mandant-ID liegt bereits in `/me`;
die aktuelle Domain ist `location.hostname` — im Browser per Definition
korrekt, weil der Host die Mandant-Auflösung *determiniert*. Was fehlt, ist
eine reine **Frontend-Komponente** plus ein Hook. Der Aufwand ist ein
Frontend-Feature, kein Backend-Feature. Die einzige „API-Frage" ist negativ
beantwortet und wird unten unter *API* festgeschrieben.

### Invariante: ein Host = genau ein Mandant

Das ist die unbequeme Frage aus der Recherche, und sie ist eindeutig
beantwortet — **nach oben, nicht nach unten**:

- `mandant_domains.hostname` ist **global unique**:
  `$table->string('hostname')->unique()` — der Migrations-Docblock sagt es
  ausdrücklich: *„The unique constraint on `hostname` doubles as the lookup
  index for `MandantContext::resolve()`*.
- `MandantDomainController::store()` erzwingt das zusätzlich per
  `Rule::unique('mandant_domains', 'hostname')` → **422**, regressionsgesichert
  in `test_rejects_duplicate_hostname_globally`.

**Ein Host kann heute also nicht zwei Mandanten bedienen**, und der
`->value()`-Aufruf in `resolve()` ist unreachable-ambiguous: es gibt nie eine
zweite Zeile, die er stillschweigend „gewinnen" könnte. Für das Dropdown heißt
das: **eine Domain gehört genau einem Mandanten, es gibt nichts zu
entweder/oder.** Eine Option pro (Mandant, Domain) wäre also_affektiv äquivalent
— die Wahl ist rein ästhetisch (siehe E3).

**Latente Fail-open-Stelle (Bleibsel, kein Blocker für dieses Feature):** Würde
der Unique-Index je fallen (oder ein Seeder/Test `MandantDomain::create()` ohne
Validierung nutzen), nimmt `->value()` eine beliebige erste Zeile und der
Resolver liefert **still** einen der beiden Mandanten. Das ist genau die
Fehlerrichtung, die dieses Feature am wenigsten verträgt: der Switcher würde
dem Admin einen Mandanten anbieten, der gar nicht der ist, den er bekommt.
**Als eigenes Backend-Ticket zu behandeln, nicht in dieser Spec** — siehe
*Nicht Teil dieser Spec*.

### Entscheidungen

Jede ist eine **Entscheidung**, keine Beobachtung — im Review bitte
gesondert prüfen.

- **E1 — Sichtbarkeit: nur `super_admin`.** `mandants.manage` ist
  super_admin-only (`backend/config/permissions.php:25-27`, Matrix :69-96), also
  kann nur diese Rolle die Datenquelle `GET /api/admin/mandants` überhaupt
  lesen. `mandant_admin` ist ohnehin auf seiner Domain und würde ein Werkzeug
  sehen, das er nicht benutzen darf. **Nebenwirkung, die man explizit will:**
  Das Feature kann **keine** neue Datenfreigabe erzeugen — die API, die es liest,
  ist für alle anderen schon 403. Begründung: Der sichtbare Ort ist der einzige
  Weg, die Berechtigung ohne Additional-Gate verständlich zu halten; ein
  `mandant_admin`-Dropdown mit nur einem Eintrag wäre Rauschen mit Versprechen.

- **E2 — Ort: `navbar-end`, vor dem E-Mail-Span.** Nicht in der Nav-Liste.
  Begründung: (a) Die Nav-Liste ist ein `<ul>` mit `<NavLink>`en und wird
  **zweimal** instanziiert (Desktop + Mobile-Drawer) — ein Kontextschalter
  darin hieße zwei Instanzen, doppelten State und zwei ARIA-Bäume. (b) Der
  Header ist bei **jedem** Viewport genau einmal da, inklusive der
  Mandanten-CRUD-Routen, auf denen die Nav-Liste den „Ort"-Fragen nicht
  antwortet. (c) Der Schalter ist ein **Kontextanzeiger** („in welchem Verband
  arbeite ich?"), kein Navigationsziel — er gehört neben die Identität
  (E-Mail/Abmelden), nicht in die Navigation.

- **E3 — Zeilenmodell: eine Zeile pro Mandant, Ziel = erste Domain nach
  aufsteigender `id`.** Alle Domains des Mandanten stehen als zweite Zeile in
  der Zeile, nichts wird verborgen. Begründung: Die Frage ist „in welchem
  **Verband** arbeite ich", nicht „unter welchem Hostnamen"; der Seed liefert
  3 + 2 Domains, eine Option pro Hostname ergäbe fünf fast identische Zeilen
  (`Hauptseite · localhost`, `Hauptseite · accreditation.test`, …) und eine
  `aria-current`-Markierung, die pro Host statt pro Verband schwankt. Die
  Reihenfolge `id` aufsteigend ist dieselbe, die `MandantDomainController::index`
  per `orderBy('id')` liefert, und dieselbe „erste Domain"-Konvention, die die
  Media-Ablage benutzt. **Verworfen:** eine Zeile pro Domain.

- **E4 — Ein Mandant ohne Domain ist ein realer, erreichbarer Zustand und wird
  nicht als Navigationsziel angeboten.** Belegt: `MandantController::rules()`
  kennt **kein** Domain-Feld, `test_can_create_mandant` legt einen Mandanten
  mit `data.domains = []` an, und der E2E `create mandant with domain and team`
  fügt die Domain erst **danach** auf der Detailseite hinzu. Die Zeile rendert
  als **nicht klickbar**, mit dem Hinweis `keine Domain` (kein Link, kein
  `href`). Begründung: Es gibt schlicht keine URL, wohin man navigieren könnte —
  eine Zeile ohne Ziel wäre eine Lüge. Der Weg zur Nacharbeit ist der
  bestehende Nav-Eintrag `Mandanten` → Detailseite → `Domain hinzufügen`; **kein
  zusätzlicher Link in der Zeile** (siehe E8, warum).

- **E5 — Ein inaktiver Mandant ist kein Ziel.** `is_active: false` ⇒
  `resolve()` filtert über `active()` ⇒ die Domain liefert `null` ⇒ 404
  (`test_resolve_ignores_inactive_mandants`). Die Zeile rendert als nicht
  klickbar mit `inaktiv`-Badge — **dasselbe Muster, das `VenueCombobox` für
  deaktivierte Spielorte bereits fährt** (`badge-ghost badge-sm` + `inaktiv`).
  Begründung: Ein Ziel anzubieten, das nach dem Klick mit 404 oder der
  Login-Seite endet, ist schlechter als es zu sagen.

- **E6 — Beim Wechsel wird nichts persistiert.** Der Host ist die einzige
  Wahrheit. Kein Cookie, kein `localStorage`, kein Query-Parameter, kein
  Server-State. Der Dropdown setzt `location.href` auf die gebaute
  Ziel-URL. Begründung: Alles andere würde einen zweiten Kontext-Kanal neben dem
  Host eröffnen, den man dann auch invalidieren müsste (Cache, A3-Invalidierung,
  Tab-Fokus nach Reload) — für einen Schalter, dessen einzige Funktion
  „gleiche Seite, andere Domain" ist.

- **E7 — Der Pfad wird unverändert mitgenommen.** Ziel-URL =
  `` `${location.protocol}//${hostname}${location.pathname}${location.search}` ``.
  Auch `/admin/mandants/:id` wandert mit — die `{mandant}`-ID ist host-unabhängig
  (`assertMandantRouteParameter()` gibt dem `super_admin` jeden Mandanten von
  jedem Host frei), die Seite bleibt also sinnvoll. **Kein Sonderfall pro
  Route-Familie:** eine Ausnahmeregel „auf der Mandanten-CRUD landet man auf
  der Liste" wäre eine Regel, die beim nächsten Ausbauen jemand falsch trifft.
  **Kein Port in der Ziel-URL** (die Hostnamen aus der DB sind port-los; ein
  Dev-Port gehört zur Dev-Origin, nicht zum Mandanten) — Folge für lokal siehe
  *Fehlerfälle*.

- **E8 — `<a href>`-Zeilen, kein Listbox/Combobox.** Das ist die bewusste
  Abweichung vom `VenueCombobox`-Muster, **mit** der Lehre daraus. Der Schalter
  ist eine **Navigation über eine Origin-Grenze**; ein Link ist die
  wahrheitsgemäße Rolle und liefert kostenlos mit, was ein Listbox-Implementierer
  nachbauen müsste: Mittelklick, „in neuem Tab öffnen", „Link-Adresse
  kopieren", und — das eigentliche Sicherheitsargument — der Browser zeigt am
  Statusrand die **Ziel-Domain**, bevor man klickt. „Du bist hier" ist
  `aria-current="true"` auf der Zeile, nicht `aria-selected` in einer Listbox.
  **Damit entfällt die ganze Fehlerklasse, die `VenueCombobox` treffen musste:**
  keine interaktiven Elemente in einer `role="option"`, keine
  `role="presentation"`-Zeilen, keine `aria-activedescendant`-Buchführung, die
  auf eine geschrumpfte Liste zeigen kann. Das Panel ist eine
  **Disclosure** (`aria-expanded` + `aria-controls` am Trigger) über einer
  `<ul>` aus Links. **Verworfen:** `role="combobox"` + `role="listbox"` +
  `role="option"` (die `VenueCombobox`-Vorbild-Rolle, in der der echte
  ARIA-Fehler gefunden und behoben wurde) und ein natives `<select>` wie
  `LanguageSwitcher` — Letzteres kann keine Badge-Zeilen mit Begründung
  („inaktiv"/„keine Domain") rendern, und das ist ein Kernbestandteil von E4/E5.

- **E9 — Schema und Port folgen `location`.** Protokoll aus
  `location.protocol` (prod `https:`, lokal `http:`), Port **nie**. Die
  gespeicherten Hostnamen tragen kein Schema, also muss etwas es liefern; die
  laufende Origin ist die ehrliche Quelle. **Annahme, die dadurch entsteht:**
  alle Mandant-Domains laufen über dasselbe Schema wie die aktuelle Domain.
  Ein gemischtes Deployment bräuchte ein Schema pro Mandant — das ist ein
  eigenes Feld und **nicht** Teil dieser Spec (siehe *Nicht Teil dieser Spec*).

- **E10 — Neu anmelden auf der Zieldomain ist das erwartete Verhalten, nicht
  ein Fehler.** Das Auth-Cookie ist **host-only**: `Controller::respondWithToken`
  ruft `cookie(… , '/', null, …)` — `$domain = null` ⇒ der Browser sendet das
  Cookie **nicht** an die andere Domain. Begründung: Genau das ist Absicht und
  keine Nebensache — ein Cookie auf `.example.test` wäre von jeder
  Schwester-Subdomain les- und überschreibbar, also eine echte Abschwächung
  (AGENTS.md §11, httpOnly-Cookie-Auth). Ein „Switch-Ticket" (Einmal-Token im
  URL-Fragment, `POST /api/auth/switch`) würde den Wechsel nahtlos machen,
  bringt aber ein Bearer-Token-in-einer-URL, einen neuen Endpunkt und eine
  neue Replay-Fläche mit — **bewusst nicht jetzt** (siehe *Verworfene
  Alternativen*). Der Ablauf ist ohnehin stimmig: `RequireAdmin`/`RequireAuth`
  leiten mit `state.from = location.pathname` auf `/login` um, und `LoginPage`
  kehrt nach dem Login auf `from` **zurück** — man landet also auf der
  Zieldomain direkt auf derselben Admin-Route.

### Vertrag im Detail

**Datenquelle.** `useMandants()` (neu in `frontend/src/logic/`) mit
`useSWR<Mandant[]>('/api/admin/mandants', listMandants)` — **identischer
SWR-Key** wie `MandantListPage.tsx:30`, damit Liste und Switcher einen
Cache teilen und das Öffnen des Switchers keinen zweiten Request kostet. Der
Umzug von `MandantListPage` auf denselben Hook ist ein **empfohlener**
Follow-up, kein Pflichtbestandteil — diese Spec erzwingt keinen Refactor, um
ihren eigenen Umfang nicht zu sprengen.

**Sichtbarkeit im Header.** Trigger-Text ab `sm`: `{name} · {hostname}`; unter
`sm` nur `{name}` mit `truncate` (der Header ist auf kleinen Viewports eng; die
E-Mail ist dort schon `hidden sm:inline`). `aria-label` **immer**
`Verband: {name} ({hostname})` — Assistive Technik verliert die Domain nie.
Chevron-Icon `aria-hidden` (dieselbe Begründung wie in `VenueCombobox`:
der Trigger selbst trägt die Semantik).

**Zeileninhalt.** Primärzeile `{name}`, Sekundärzeile alle Hostnames
(Komma-getrennt, `truncate`); rechts ein `inaktiv`-Badge (E5) oder ein
`keine Domain`-Text (E4). Ist die Zeile der aktuelle Mandant
(`mandant.id === user.current_mandant_id`): `aria-current="true"`,
`menu-active`, **kein `href`**, Klick schließt nur das Panel. Für inaktive und
domainlose Mandanten gilt dasselbe (kein `href`).

**Tastatur und Fokus.** `Enter`/`Space`/`ArrowDown` am Trigger öffnet und setzt
den Fokus auf die **erste fokussierbare** Zeile (bei inaktiv/domainlos also auf
die erste **aktive** — ein Fokus auf eine tote Zeile ist eine Sackgasse);
`ArrowUp`/`ArrowDown` bewegen den Fokus (kein Wrap, kein `aria-activedescendant`
nötig, weil der Fokus real ist), `Home`/`End` an den Rand, `Escape` schließt und
gibt den Fokus **an den Trigger** zurück, `Tab` schließt und lässt den Fokus
weiterlaufen (kein Fokusfalle — der Drawer hat dafür schon das Muster in
`AdminLayout.tsx:152-177`). Außenklick schließt. `mousedown` auf dem Panel wird
verhindert, damit der Klick die Zeile trifft statt sie zu fokussieren und
danach wegzuklicken (das Muster aus `VenueCombobox.tsx:402`).

**Warum kein Fokusfalle-/Kontrast-Sonderfall:** daisyUIs `dropdown` ist reines
CSS; das Panel ist nur gerendert, wenn es offen ist, und `btn-ghost`/`menu-active`
sind die Standard-Token. Der Design-QA-Loop (§7) ist nach der Umsetzung
verpflichtend — für diesen Schalter gibt es bisher **keinen** Baseline-Screenshot.

**Datenschutz-Randnotiz (kein Security-Blocker):** Der Trigger zeigt in der
Geschlossenen Darstellung Name **und** Domain an. Für einen `super_admin` ist das
keine neue Offenlegung — er kann `GET /api/admin/mandants` ohnehin abrufen,
jeder Mandant hat ein öffentliches Portal, und sein Hostname steht in der
öffentlichen `trustHosts`-Liste (`bootstrap/app.php:62-88`).

### API

**Keine Erweiterung.** Ausdrücklich festgehalten, damit sie nicht als
versehentliche Lücke missverstanden wird — und damit niemand sie *nachträglich*
baut, weil sie „fehlt":

| Bedarf | Bereits vorhanden |
|---|---|
| Mandant-Name, Domains, `is_active` | `MandantResource` (`domains`, `is_active`, `is_primary`), `MandantController::index` |
| Aktueller Mandant | `UserResource.current_mandant_id` aus `/api/auth/me` |
| Aktuelle Domain | `location.hostname` im Browser (die Origin **ist** der Host) |
| Rolle | `isSuperAdminUser(user)` (`frontend/src/logic/adminRoles.ts:9-11`) |

**Was ausdrücklich NICHT gebaut wird:** kein `GET /api/admin/context` oder
`/api/admin/mandants/{id}/switch`, kein Feld `scheme` pro Mandant (E9), kein
`current_domain` in `UserResource` (der Browser kennt seine Domain), keine
Mandant-Parametrisierung der host-skalierten Routen. Ein neuer Endpunkt wäre
hier nicht nur überflüssig, sondern würde eine **zweite** Kontext-Quelle
eröffnen, deren Invalidierung man parallel zur Host-Auflösung pflegen müsste.

### Fehlerfälle

| Fall | Verhalten | Begründung / Beleg |
|---|---|---|
| Zieldomain **DNS/TLS nicht erreichbar** | Der Browser zeigt seine eigene Fehlerseite — die App ist bereits verlassen. **Nicht abfangbar**, und so zu akzeptieren, statt es mit einem Alibi-Dialog zu überdecken. | Eine Top-Level-Navigation ist unumkehrbar; ein Fehlerdialog *vor* dem Verlassen würde jeden Klick blockieren. |
| Zieldomain **löst zu einem inaktiven Mandanten auf** | Von vornherein nicht als Ziel angeboten (E5). Wer die URL von Hand eintippt, bekommt 404 von der Middleware ⇒ `/api/auth/me` schlägt fehl ⇒ `RequireAdmin` zeigt die Login-Seite. | `resolve()` filtert `active()`; `test_resolve_ignores_inactive_mandants` |
| **Aktuelle Domain** ist im Panel nicht die erste des Mandanten | Zeile wird über die **Mandant-ID** markiert, nicht über den Host; die Domains des Mandanten stehen sichtbar in der Zeile. | `current_mandant_id` ist die Feldquelle; `location.hostname` ist Anzeige. |
| **Lokal:** `*.localhost:5173` | Löst per Loopback-Fallback **immer** auf den **primären** Mandanten auf. Ein Entwickler, der den Switch lokal testet, sieht also den primären Verband und nicht den Seed-Mandanten `bundesliga`. | `MandantContextMiddleware::isLoopback()` :125-135 + `handle()` :56-60, P3e-B3; `resolveHost()` :83-99 nimmt im `local`-Dev den Vite-Referer nur für `*.localhost:5173` an |
| **Lokal:** Dev-Port | Die Ziel-URL trägt bewusst **keinen** Port (E7) — ein Wechsel verlässt die Vite-Dev-Origin. Für einen echten Test muss der Host gemappt sein (`/etc/hosts` → Herd-Backend) und der Mandant aktiv sein. | E7/E9 |
| `trustHosts`-Allow-List veraltet (A3) | Ein frisch angelegtes oder frisch gelöschtes Domain ist bis `MANDANTS_CACHE_TTL` (3600 s) nicht allow-listed ⇒ der erste Klick darauf endet in **400**, nicht 404. Bekanntes, akzeptiertes Risiko (A3), durch `MandantDomainController::store/destroy` invalidiert. | `AGENTS.md` §10 A3; `bootstrap/app.php:62-88`; `MandantContext::forgetHostnames()` |
| **Login-Throttle** beim erneuten Anmelden auf der Zieldomain | Nach mehreren Wechseln zwischen zwei Domains greift das geteilte Per-IP-Login-Limit. Dokumentiert, nicht umgangen. | `throttle:login` |

### Tests

DoD §3: Frontend-Logik → Vitest, UI → Playwright E2E, Backend → PHPUnit.

- **Vitest (neu, Pflicht)** — `MandantSwitcher.test.tsx`:
  1. rendert **nur** für `super_admin` (für `mandant_admin` kein Trigger);
  2. blendet einen Mandanten **ohne** Domain als nicht klickbar ein (kein `href`)
     und einen **inaktiven** mit `inaktiv`-Badge;
  3. markiert den aktuellen Mandanten mit `aria-current="true"` und ohne `href`;
  4. baut die Ziel-URL als `` `${location.protocol}//${hostname}${pfad}?<search>` ``
     und **ohne** Port;
  5. Tastatur: `ArrowDown`/`ArrowUp`/`Home`/`End` bewegen den Fokus,
     `Escape` schließt und legt den Fokus auf den Trigger zurück;
  6. Fehlerzustand der Liste ⇒ Panel zeigt eine übersetzbare Meldung statt
     einer leeren Liste (kein `page`-Leerbild).

- **Vitest (Kleinigkeit, Pflicht)** — ein Fall für `useMandants`, dass der
  SWR-Key exakt `'/api/admin/mandants'` ist (Cache-Teilung mit
  `MandantListPage`).

- **Playwright (neu, Pflicht)** — `frontend/tests/e2e/admin-mandant-switch.spec.ts`,
  Tags `{ tag: ['@regression', '@feature:admin:mandant'] }`, **Desktop-Chrome-only**
  (`test.skip` auf dem Mobile-Projekt, Muster aus `admin-mandant.spec.ts`/`a11y.spec.ts`:
  das geteilte Login-Limit soll nicht doppelt verbraucht werden):
  1. **Inhalt:** Der Trigger zeigt den aktuellen Mandanten; das Panel listet
     jeden Mandanten **mit** seiner Domain; ein inaktiver und ein
     domainloser Mandant sind nicht klickbar.
  2. **Ziel-URL:** `href` einer aktiven Zeile ist exakt
     `https://<hostname>/admin/…` bzw. `http://…` auf der E2E-Origin. (Reines
     Attribut-Assert — kein Netzwerk.)
  3. **Klick:** Der Klick wird mit `page.route()` **abgefangen**, bevor DNS
     berührt wird (der Ziel-Host `bundesliga.test` hat im E2E-Container keinen
     Eintrag), der Handler belegt die angeforderte URL und beantwortet mit
     minimalem HTML. Assertiert werden: die abgefangene URL **und** der
     übernommene Admin-Pfad. *Kontingenz:* falls sich das Abfangen in CI als
     unzuverlässig erweist, auf Variante 2 zurückfallen (nur `href`) — und das
     im Verifikationsbericht **sagen**, nicht stillschweigend tauschen.

- **PHPUnit (ein neuer Test, gezielt):** Der Switch setzt **Host**-auflösung
  voraus, und diese Kette ist bisher nur zur Hälfte gemessen: `MandantContextTest`
  prüft die Auflösung (`test_middleware_sets_current_mandant_for_known_host`
  nutzt `$this->get('http://bundesliga.test/')`), und `AdminTeamTest.php:426-448`
  prüft den Host-Kontext, setzt ihn aber **manuell** über `MandantContext::set()`.
  **Neu:** ein `super_admin` ruft `GET http://<host-b>/api/auth/me` **über die
  echte Middleware** und erhält `data.current_mandant_id === mandantB.id`
  (nicht A, nicht den primären). Genau diese Aussage ist die Grundlage von E10
  und der einzige Ort, an dem ein Fehler nicht sichtbar würde, sondern nur
  "falsche Daten" liefert.

- **PHPUnit (bestehende Deckung genügt, keine Neuschreibung):**
  `AdminMandantTest::test_rejects_duplicate_hostname_globally` (die 1:1-Invariante),
  `::test_index_orders_mandants_by_name` (Index-Form/Ordering),
  `::test_can_create_mandant` (`data.domains = []` — der domainlose Mandant),
  `MandantContextTest::test_resolve_ignores_inactive_mandants` (E5). Diese
  müssen grün **bleiben**; neue Tests wären Duplikate.

- **Nach der Umsetzung verpflichtend:** `pnpm lint:fix && pnpm build`
  (inkl. `pnpm lingui:extract && pnpm lingui:compile`, `frontend/AGENTS.md`),
  `pnpm test:run`, `php artisan test`, und **einmal** der Screenshot-/
  Vision-Loop für die Admin-Routen (Position 5 im Batch — dieser Schalter
  verändert den Header auf **jeder** Admin-Seite, das ist der erste Anlass, den
  Loop überhaupt zu fahren).

### Verworfene Alternativen

- **Mandant-Parametrisierung aller Admin-Routen** (`/api/admin/mandants/{id}/…`
  für Kategorien, Events, Akkreditierungen, Freigaben, Venues, Users).
  **Ausdrücklich nicht beschlossen (D21).** Das ist die große Variante: sie
  verdoppelt die Routen-Oberfläche, braucht je Controller eine zweite
  Scope-Variante und macht den Host zum Optional-Parameter — mit allen
  Fehlerklassen, die daraus folgen (vergessene `assertMandantScope`,
  gemischte Hosts, Caching). Dass heute **zwei** Ausnahmen existieren
  (`/api/admin/mandants/{mandant}/teams`, `/api/admin/mandants/{mandant}/venues`
  — beide wegen genau *einer* mandant-adressierten Seite) ist **kein Argument
  für die große Variante**, sondern der Grund, warum sie abgelehnt wurde. Wer
  sie will, muss begründen, warum die zwei Einzelfälle nicht reichen.
- **Ein „Switch-Ticket"** (Einmal-Token, `POST /api/auth/switch`, Token im URL
  Fragment) für einen nahtlosen Wechsel ohne erneuten Login. Trägt ein
  Bearer-Token in einer URL (Referrer-Logs, History, Screenshots) und eine
  neue Replay-Fläche. **Nicht jetzt** — E10 begründet den Preis.
- **Mandant-Session** (`session('mandant')`, ein Cookie, ein `?mandant=`-Parameter,
  ein Header vom Client). Zwei Wahrheiten nebeneinander, jeweils mit
  Invalidierungspflicht. Verworfen zugunsten von E6.
- **Alle Rollen sehen den Schalter** (mandant_admin mit genau einem Eintrag).
  Verworfen zugunsten von E1.
- **Natives `<select>`** wie `LanguageSwitcher`. Verworfen zugunsten von E8
  (keine Badge-/Hinweiszeilen).

### Nicht Teil dieser Spec

Bewusst hier festgehalten, damit es nicht verloren geht und **nicht** als
versehentliche Lücke mitimplementiert wird:

1. **`resolve()` schärfen.** `->value('mandant_id')` nimmt still die erste
   Zeile. Heute durch den Unique-Index unerreichbar, aber fail-open, falls der
   Index je fällt. Richtige Form: `->first()` plus ein expliziter Fehler, wenn
   mehr als ein Treffer existiert, plus ein Test dafür. Eigenes
   Backend-Ticket, eigener Diff.
2. **`scheme` pro Mandant** (für gemischte http/https-Deployments) — E9
   dokumentiert die Annahme; ein eigenes Feld wäre ein Schema-Change plus
   Trust-/Cookie-Folgearbeit.
3. **Session über die Domaingrenze hinweg** — E10, siehe *Verworfene
   Alternativen*.
4. **Der stille Cross-Mandant-Write** auf der Mandanten-Detailseite ist mit
   `a2c8e5f` behoben; die Venue-Liste oben („Lösungspfad") ist der
   Reststand der Dokumentation und wird durch dieses Feature **nicht** berührt.

## Seed (Ist P1)

- `DatabaseSeeder` ist idempotent (`firstOrCreate`):
  - Admin-User (`ADMIN_EMAIL`/`ADMIN_PASSWORD`) als **globaler super_admin**
    (`mandant_id = NULL`), `email_verified_at` wird ggf. backfilled.
  - Primary-Mandant `main` („Hauptseite", `is_primary`, `teams_enabled: false`)
    mit Domains `localhost`, `accreditation.test`, `www.accreditation.test`.
  - Zweiter Mandant `bundesliga` mit `bundesliga.test`/`www.bundesliga.test`.
- `RoleSeeder` legt die fünf Rollen via `firstOrCreate` auf `roles.slug` an.

## Rollen-Hierarchie

`super_admin` (global) → `mandant_admin` (Verband) → `team_admin` (Verein,
P2) → `user` → `verifier` (Ordner). Team-Admin sieht Verbands-Akkreditierungen
eigener Personen **read-only** (D7, P2/P3). Details in
`features/auth/01-auth-and-roles.md`.

## Hardening / Follow-up (offen, P2/P7)

- **B1 (low):** Negative-Cache für **unbekannte** Hosts (60s-TTL), damit
  Host-Flooding nicht die `mandant_domains`-Query je Request trifft.
- **B2 (low):** Referer-Fallback auf die Vite-Origin (z. B.
  `localhost:5173`) einschränken statt jeden Referer-Host in `local` zu
  akzeptieren.
- **B3 (high vor P1b-Auth):** Prod `Request::trustHosts()` aus
  `mandant_domains` speisen, bevor Auth-Endpunkte in Prod gehen.
- **B4 (low):** Config-Kommentar in `config/mandants.php` — der Primary-
  Mandant wird nicht gecacht, nur die Host→ID-Auflösung (Kommentartext
  konsistent halten).
