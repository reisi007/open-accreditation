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
  `/admin/accreditations`, `/admin/users`, `/admin/venues`. Diese Routen tragen
  **keine** Mandant-ID in der URL — es gibt also nichts, was das SPA durchreichen
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

### Venue auf der Mandant-Detail-Seite: zwei Skalen, eine Scope-Regel

Hier **kreuzen** sich die beiden Modelle, und `Venue` ist die einzige Fläche,
die **beide** Skalen wirklich braucht: host-relative Seiten (Kategorien,
Events, Akkreditierungen, `VenuesPage`) und die eine mandant-adressierte
Admin-Seite (`/admin/mandants/{id}`) liegen auf derselben Oberfläche. Beide
Varianten existieren deshalb **nebeneinander**, mit **identischem** Gate
(`venues.manage`) und ausschließlich unterschiedlichem Scope:

| Route | Mandant aus … | Für wen |
|---|---|---|
| `GET/POST/PUT/DELETE /api/admin/venues` | dem **Host** (`currentMandantId()`) | host-relative Seiten (`VenuesPage`, die Formulare auf Kategorien-/Event-/Akkreditierungs-Seiten) |
| `GET/POST/PUT/DELETE /api/admin/mandants/{mandant}/venues` | der **URL**-`{mandant}` | `MandantDetailPage` — Teams und Heimstätte aus derselben Quelle |

Beide sind **vollständig** (alle vier Methoden, nicht nur Lesen und Anlegen) und
teilen sich die privaten `*ForMandantId()`-Arbeiter, damit abgeleitete
`teams_count`/`events_count`, die Unique-Regel und die 409-Delete-Politik nicht
auseinanderdriften können. Beleg: `backend/routes/api.php:244-249` (host-skaliert)
und `:264-269` (adressiert).

**Die adressierte Fläche ändert den Scope, nicht die Reichweite.** Das Gate
bleibt `venues.manage`, und `assertMandantRouteParameter()` gibt dem
`super_admin` jeden Mandanten von jedem Host frei, allen anderen nur den, auf
dem sie ohnehin sind (sonst 404). Ein `mandant_admin`/`team_admin` bekommt über
den adressierten Pfad also exakt die Venues, die ihm der host-skalierte Pfad
schon gab: es entsteht **keine** neue Reichweite, die Seite liest und schreibt
nur endlich den Mandanten, um den es geht.

**Im Frontend ist der Unterschied ein Argument, kein Duplikat.** `useVenues()`
nimmt eine optionale `mandantId`; `null` ⇒ host-skaliert, eine ID ⇒
mandant-adressiert (`venuesKey()`, `useVenues.ts:20-21`). `MandantDetailPage`
reicht seine `{mandant}`-ID durch `TeamForm` bis in den `VenueCombobox`, dessen
**Inline-Create** damit in denselben Mandanten schreibt wie die Auswahl davor.
Ohne das wäre die Anzeige richtig, der Create aber ein stiller
Cross-Mandant-Write — und der fällt erst beim anschließenden Team-Save auf
(`TeamController::assertVenueOfMandant()`, 404), also **nach** dem Schaden.

**Ein Team-/Event-Write invalidiert beide Listen.** Die Venue-Liste trägt
abgeleitete `teams_count`/`events_count`, und beide Flächen zeigen sie an;
`refreshVenueLists(mandantId)` invalidiert deshalb **beide** Keys
(`useVenues.ts:32-33`, `:44-45`), damit keine Seite einen veralteten Zähler
zeigt, nur weil sie die jeweils andere Fläche liest. `MandantDetailPage` ruft
das nach Team-Save und Team-Delete (`MandantDetailPage.tsx:158`, `:178`).

**Invariante mit zwei Skalen — und sie gilt für `Venue` auf beiden:** Jeder
Write wird gegen **den Mandanten validiert, auf den seine Route skaliert** —
host-abgeleitet (`ResolvesAdminTeamScope::assertMandantScope()`,
`ResolvesAdminTeamScope.php:149-151`) auf den host-skalierten
Admin-Oberflächen, gegen den **URL**-`{mandant}` auf der
Mandant-CRUD-Oberfläche, wo die Auflösung **strukturell** durch die Mandant
läuft (`$mandant->venues()->findOrFail()`, `VenueController.php:130`, `:142`) —
also per Konstruktion ein 404 und keine Prüfung, die man irgendwann vergessen
könnte. Cross-Mandant-IDs antworten in beiden Fällen mit 404, ein falscher
Kontext führt also nie zu einem Team, Event, einer Akkreditierung, einer **Venue**
oder einem Konto im falschen Mandanten.

Die beiden Achsen bleiben dabei unterscheidbar, und das ist beabsichtigt: **404
ist der Mandanten-Achse vorbehalten, 403 der Team-/Rollen-Achse**
(`assertOwnership()`, `assertMayWrite()`, das `team_admin`-Schreibrecht auf
geteilte Venues, `ResolvesAdminTeamScope.php:187`). Ein 403 würde eine
Beziehung behaupten, die es nicht gibt („du darfst das nicht"), während 404 die
Wahrheit sagt und weniger preisgibt — der Mandant ist von diesem Host aus nicht
erreichbar.

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
| Wie erfährt der Store den aktuellen Mandanten? | `/api/auth/me` → `UserResource.current_mandant_id = MandantContext::currentId()` (host-abgeleitet, `null` ohne Kontext). Im Frontend über `useAuth()` (`useSWR('session')`) verfügbar. | `backend/routes/api.php:98`; `backend/app/Http/Resources/UserResource.php:47`; `frontend/src/logic/useAuth.ts:20-22`; `frontend/src/api/types.ts:9-19`; die zwei aktuellen Verbraucher: `frontend/src/logic/useAdminTeams.ts:40-42` (Team-Quellen) und `frontend/src/components/MandantSwitcher.tsx:122` (die „Du bist hier"-Zeile) |
| Wo käme das Dropdown hin? | `AdminLayout` rendert einen `<header className="navbar">` mit `navbar-start` (Logo-Link) und `navbar-end` (Menü-Button, **Domainwechsel**, E-Mail, Abmelden, `LanguageSwitcher`). `AdminNav` ist eine **lokal definierte Komponente derselben Datei** und wird **zweimal** instanziiert (Desktop-`aside` + Mobile-Drawer-`aside`). | `frontend/src/pages/admin/AdminLayout.tsx:118` (`AdminLayout`), Header :207-247, `navbar-end` :214-247, `MandantSwitcher` :235 (vor dem E-Mail-Span :236), `LanguageSwitcher` :246; `AdminNav` :19-116, zweimal :256 und :279 |
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

- **E11 — `useMandants(enabled)`: der Hook fragt nur, wenn er etwas anzeigen
  darf.** Signatur `useMandants(enabled = true)`, `enabled: false` ⇒ `null` als
  SWR-Key (SWRs „noch nicht laden"). `MandantSwitcher` ruft
  `useMandants(isSuperAdmin)`. Begründung: Die Datenquelle liegt hinter
  `can:mandants.manage` und ist damit super_admin-only (E1) — für jeden
  Rollen-`user` ohne dieses Recht wäre der Request ein **garantierter 403**, und
  der Trigger hängt in `navbar-end`, also auf *jeder* Admin-Seite (E2). Das ist
  **keine** Access-Control-Entscheidung: die API verweigert ohnehin, es wird nur
  kein Request gestellt, der ausschließlich scheitern kann. Der Parameter ist
  damit Teil des Vertrags — ohne ihn sähe der nächste Refactor hier eine
  Begründung, die es nicht gibt. Beleg: `useMandants.ts:36-37`,
  `MandantSwitcher.tsx:110`.

- **E12 — Der Trigger-`aria-label` fällt auf den Hostnamen allein zurück, nicht
  auf eine leere Klammer.** Solange ein Mandant bekannt ist:
  `Verband: {name} ({hostname})` — Assistive Technik verliert die Domain nie.
  Ist `current_mandant_id` `null` (Liste lädt noch, oder sie ist fehlgeschlagen),
  rendert der Trigger **nur** `location.hostname`. Begründung: `Verband: (
  host)` wäre eine Behauptung ohne Inhalt (welcher Verband?), und der Hostname ist
  genau das, was E1/E9 vom Trigger **nie** verlieren will — die Domain *ist* der
  Kontext, der Name nur sein Etikett. Der Test sucht den Trigger im Fehlerfall
  deshalb **über die Rolle**, nicht über den Namen
  (`MandantSwitcher.test.tsx:349-350`). Beleg: `MandantSwitcher.tsx:250`.

- **E13 — Nicht navigierbare Zeilen tragen `aria-current="false"`; daisyUIs
  `menu-disabled` ist dafür verworfen.** daisyUIs aktive-Zeilen-Regel verlangt
  `[aria-current]:not([aria-current=false],[aria-current=""])`, und ihre
  Hover-Regel trifft jedes direkte `<li>`-Kind (außer `.menu-disabled`).
  `="false"` ist damit der eine Wert, der in **beiden** Sprachen dasselbe sagt —
  „**nicht** der aktuelle Mandant" — statt die Zeile stillschweigend zu lassen;
  ein klickbares Link-Ziel trägt entsprechend gar kein `aria-current`.
  `menu-disabled` — daisyUIs eigener „nicht klickbar"-Marker — ist doppelt
  unbrauchbar: es setzt `color: color-mix(in oklab, base-content 20%, transparent)`,
  gemessen **1.54:1** gegen `base-100` (bei 12–14 px weit unter WCAG AA), und
  `pointer-events: none`, was dem Klick auf die aktuelle Zeile (schließt nur das
  Panel) die Zeiger-Bedienung genommen hätte. Ehrliche Grenze: die **Hover**-
  Hinterlegung der `menu`-Regel trifft weiterhin auch eine Zeile mit
  `aria-current="false"` — Kosmetik, kein Linkversprechen, weil kein `href`
  existiert und die Tastatur über sie springt.

- **E14 — Außenklick über `document`-`pointerdown`, nicht `onBlur` auf dem
  Wrapper.** `VenueCombobox` schließt per `blur`; das deckt den Fall nicht, den
  man am häufigsten trifft: ein Klick auf den Seitenhintergrund bewegt den Fokus
  **nirgends** hin, es feuert also **kein** `blur`, und das Panel bliebe über dem
  Inhalt offen. Gemessen im Test: `user.click(document.body)` löst genau das aus.
  Ein `pointerdown`-Listener auf `document` feuert für jeden Klick; der
  `contains()`-Test hält die Klicks im Panel offen. Beleg:
  `MandantSwitcher.tsx:174-188`.

### Vertrag im Detail

**Datenquelle.** `useMandants(enabled)` (in `frontend/src/logic/`) mit
`useSWR<Mandant[]>(enabled ? MANDANTS_KEY : null, () => listMandants())` und
`MANDANTS_KEY = '/api/admin/mandants'`. Der Key ist **absichtlich derselbe**,
den `MandantListPage` für seine Liste benutzt: damit teilen Liste und Switcher
einen Cache und das Öffnen des Switchers kostet keinen zweiten Request. Der
`enabled`-Schalter ist E11, keine Optimierung.

> **Offene, benannte Lücke — der geteilte Key ist noch nicht geteilt.**
> `MANDANTS_KEY` ist exportiert, aber `MandantListPage.tsx:30` liest weiterhin
> **sein eigenes Literal** `'/api/admin/mandants'` statt des Exports. Der
> `useMandants`-Test beweist die gemeinsame Cache-Nutzung **zweier Hook-
> Konsumenten** — nicht, dass die Mandantenliste und der Switcher dasselbe
> Literal verwenden. Ein Auseinanderdriften bliebe für ihn unsichtbar: beide
> Seiten läden ihre eigene Kopie, jede bliebe in sich korrekt, und der Schalter
> kostete auf `/admin/mandants` genau den zweiten Request, den er einsparen
> soll. Der Umzug ist ein **empfohlener** Follow-up (kein Refactor-Zwang für
> diese Spec), aber eine Lücke, kein Erledigtes — bis er getan ist, ist der
> „identische Key" eine Absichtserklärung und keine Tatsache.

**Sichtbarkeit im Header.** Trigger-Text ab `sm`: `{name} · {hostname}`; unter
`sm` nur `{name}` mit `truncate` (der Header ist auf kleinen Viewports eng; die
E-Mail ist dort schon `hidden sm:inline`). In **beiden** Lagen gilt: solange
kein Mandant bekannt ist, steht statt des Namens der Hostname. `aria-label` ist
`Verband: {name} ({hostname})` und sonst der **reine Hostname** (E12) — in
keinem Fall eine leere Klammer; Assistive Technik verliert die Domain nie.
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
`AdminLayout.tsx:152-177`). Außenklick schließt über einen
`document`-`pointerdown` (E14, **nicht** `onBlur` — der gemessen fehlende Fall).
`mousedown` auf dem Panel wird verhindert, damit der Klick die Zeile trifft statt
sie zu fokussieren und danach wegzuklicken (das Muster aus
`VenueCombobox.tsx:402`).

**Kein Fokusfalle-Sonderfall:** daisyUIs `dropdown` ist reines CSS, das Panel ist
nur gerendert, wenn es offen ist, und `btn-ghost`/`menu-active` sind die
Standard-Token. Der Design-QA-Loop (§7) bleibt verpflichtend.

**Kontrast — gemessen, nicht gerechnet.** Tailwind v4 und daisyUI 5 emittieren
`oklch()` bzw. `color-mix(in oklab, …)`; die einzige ehrliche Lesart ist der vom
Browser selbst erzeugte sRGB-Wert, gelesen aus einem Canvas und gegengeprüft am
Screenshot-Pixel. Werte für das Theme `accr-light`, Panel-Hintergrund
`base-100` (Name 14 px, Domainzeile 12 px):

| Zeile | Textfarbe | gemessen |
|---|---|---|
| Link-Ziel (`<a href>`) | `base-content` | **18.10:1** |
| Link-Ziel, Domainzeile | `text-base-content/70` | **6.79:1** |
| aktuelle Zeile (`menu-active`) | `neutral-content` auf `neutral` | **6.99:1** |
| inaktiv / ohne Domain | `text-base-content/70` | **6.79:1** |

Der Zeilentext des Panels liegt damit über WCAG AA (4.5:1 für 12–14 px
Normaltext). Die **Domainzeile der aktuellen Zeile** bekommt dafür bewusst
**keine** eigene Muted-Stufe: Sie erbt die Farbe der Zeile, weil ein hart
gesetztes Muted-Grau auf `menu-active` (hell auf neutral) dunkel-auf-dunkel
wäre. Der muted-Step des Panels ist `/70` — **nicht** `/60`: `/60` misst 4.74:1
und würde AA noch gerade schaffen, aber ohne Reserve. Wer hier „aufräumt" und
eine Stufe heruntergeht, verliert die Reserve ausgerechnet für die 12-px-Zeile,
die am ehesten dran ist. `menu-disabled` dagegen bricht ein (E13, 1.54:1).

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

- **Vitest (Pflicht)** — `MandantSwitcher.test.tsx`, **11 Fälle**. Die ersten
  sechs sind der hier beabsichtigte Kern (Sichtbarkeit, Zeilen, Ziel-URL,
  Tastatur, Fehlerbild); die weiteren fünf fixieren Entscheidungen, die erst die
  Umsetzung unterwegs treffen musste und die ohne Test stillschweigend kippen
  könnten:
  1. kein Trigger **und kein** Request an `/api/admin/mandants` für einen
     `mandant_admin` (E1, E11);
  2. Trigger für `super_admin`: `aria-label` = `Verband: {name} ({host})`,
     Text `{name} · {host}`, `aria-expanded="false"` — der Host ist **Anzeige**,
     die Identität kommt aus `current_mandant_id` (E12);
  3. domainlose und inaktive Zeile: beide **ohne** `href`, mit **ihrem** Marker,
     unabhängig voneinander; eine aktive Zeile mit Domain ist ein echter Link und
     zeigt **alle** Domains des Mandanten (E3, E4, E5);
  4. `aria-current="true"` **genau einmal** und dort ohne `href`; **kein**
     `listbox`/`option`; jede andere nicht navigierbare Zeile mit
     `aria-current="false"`; ein Link-Ziel **ohne** `aria-current` (E8, E13) —
     die Unterscheidung, die ein „`aria-current` überall hin" erledigen würde;
  5. Klick auf die **aktuelle** Zeile schließt das Panel, **ohne** zu navigieren;
  6. Ziel-URL = Schema der laufenden Origin + Pfad + Query, **ohne** Port, und
     das Ziel ist die **erste Domain nach aufsteigender `id`**, nicht die
     Reihenfolge des API-Arrays (E3, E7, E9). Die Fixture-Origin läuft dafür auf
     einem Port, damit ein Port-Leak sichtbar würde, und die **Anzahl** der Links
     beweist, dass nichts zu viel angeboten wurde;
  7. `Enter` öffnet und legt den Fokus auf die erste **Link**-Zeile (tote Zeilen
     werden übersprungen); `aria-controls` am Trigger **nur** im offenen Zustand,
     weil die Referenz sonst ins Leere zeigt (E8);
  8. Pfeile und `Home`/`End` bewegen den Fokus **ohne Wrap**;
  9. `Escape` schließt und gibt den Fokus an den Trigger zurück;
  10. **Außenklick** schließt — ausgelöst auf `document.body`, also genau der
      Fall, an dem ein `onBlur`-Ansatz scheitert (E14);
  11. fehlgeschlagene Liste ⇒ übersetzbare Meldung **in der aktiven Locale**
      statt einer leeren Liste, und das `aria-label` des Triggers fällt auf den
      **Hostnamen** zurück (E12).

  **Nicht abgedeckt:** der Fall aus *Fehlerfälle* „Aktuelle Domain ist im Panel
  nicht die erste des Mandanten". Er ist implementiert (die Zeile wird über die
  **Mandant-ID** markiert, nicht über den Host), aber **kein** Vitest- und
  **kein** E2E-Test stellt einen Host her, der nicht die erste Domain seines
  Mandanten ist — beide Fixtures (Unit `hauptseite.test` = Domain-Id 1, E2E
  `localhost` = erste Seed-Domain) sind genau der *einfache* Fall. Wer die
  Markierung je umbaut, muss den Fall erst bauen.

- **Vitest (Pflicht)** — `useMandants.test.tsx`, **4 Fälle** (nicht einer):
  1. liest von **exakt** `'/api/admin/mandants'` und meldet Lade- und Fehlerzustand;
  2. **zwei** Konsumenten desselben Keys ⇒ **genau ein** Request — der Nachweis
     der geteilten Cache-Nutzung. Achtung: er beweist *zwei Hook-Konsumenten*,
     nicht dass `MandantListPage` dasselbe Literal verwendet (siehe die offene
     Lücke unter *Datenquelle*);
  3. eine fehlgeschlagene Liste erscheint als **Fehler**, nicht als leere Liste
     (sonst meldet ein 403 „diese Verbände gibt es nicht");
  4. `enabled: false` ⇒ **kein** Request (E11).

- **Playwright (Pflicht)** — `frontend/tests/e2e/admin-mandant-switch.spec.ts`,
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

- **PHPUnit (Pflicht)** — Der Switch setzt **Host**-auflösung voraus, und diese
  Kette ist nur zur Hälfte messbar, wenn der Kontext von Hand gesetzt wird:
  `MandantContextTest` prüft die Auflösung
  (`test_middleware_sets_current_mandant_for_known_host` nutzt
  `$this->get('http://bundesliga.test/')`), und `AdminTeamTest.php:426-448` prüft
  den Host-Kontext, setzt ihn aber **manuell** über `MandantContext::set()`.
  **Gebaut:** `backend/tests/Feature/MandantHostHeaderResolutionTest.php` (17
  Tests) — der Kontext wird **nirgends** von Hand gesetzt, und ein `super_admin`
  ruft `GET http://<host-b>/api/auth/me` **über die echte Middleware** und
  erhält `data.current_mandant_id === mandantB.id` (nicht A, nicht den
  primären). Genau diese Aussage ist die Grundlage von E10 und der einzige Ort,
  an dem ein Fehler nicht sichtbar würde, sondern nur "falsche Daten" liefert.
  Der Test muss dabei zusätzlich den Durchlass der Middleware für
  Console-Requests unterbinden, damit ein unbekannter Host auch wirklich 404
  liefert: `MandantContextMiddleware` lässt Console- und Testing-Requests
  bewusst durch, und PHPUnit ist ein Konsolenprozess.

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
  gemischte Hosts, Caching). Dass heute **fünf** Subressourcen mandant-adressiert
  neben ihren host-skalierten Zwillingen existieren
  (`/api/admin/mandants/{mandant}/` + `domains`, `logo`, `header`, `teams`,
  `venues` — alle fünf wegen genau *einer* mandant-adressierten Seite,
  `/admin/mandants/{id}`) ist **kein Argument für die große Variante**, sondern
  der Grund, warum sie abgelehnt wurde: Der Einzelfall ist billig (er teilt sich
  Controller und `assertMandantRouteParameter()` mit der jeweils
  host-skalierten Route), die große Variante vervielfacht die Stellen, an denen
  ein `assertMandantScope` fehlen kann. Wer sie will, muss begründen, warum die
  fünf Einzelfälle nicht reichen.
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
4. **Mandant-Parametrisierung des Schalters selbst** (etwa ein
   `?mandant=`-Parameter, der die Ziel-URL statt des Hosts adressiert) — genau
   die D21-Absage, siehe *Verworfene Alternativen*.

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
