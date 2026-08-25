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

## Bekannte Limitation: Multi-Domain-Admin-UX (Ist P2c, P2c-F4)

**Bewusst akzeptierte Limitation** (Design-Entscheidung, kein Bug): Ein
`super_admin` hat keinen mandant-scoped Rollenkontext (`mandant_id = NULL`,
global). Die Admin-UX nähert den „aktuellen Mandant" daher im Frontend an:
`useAdminTeams()` (`frontend/src/logic/useAdminTeams.ts`) wählt für ihn den
**Primär-Mandant** (`is_primary = true`) als Quelle der Team-Listen und
Team-Auswahlen (Kategorien, Events, Rollen-, Akkreditierungs-Formulare). Das
Backend selbst löst korrekt host-basiert auf (`MandantContext::currentId()`
in den Admin-Controllern, siehe Host-Resolution) — die Approximation liegt
rein im SPA.

**Folge:** Greift ein `super_admin` über eine **Nicht-Primär-Domain**
(z. B. `bundesliga.test`) zu, zeigt die Team-Auflistung fälschlicherweise die
Teams des **Primär-Mandanten** — nicht die des über die Domain adressierten
Mandanten. In Dev/Local ist das unsichtbar: Der Loopback-Fallback der
`MandantContextMiddleware` mapped `localhost`/`127.0.0.1`/`::1` ohnehin auf
den Primär-Mandant, damit ist die Frontend-Approximation dort korrekt
(Dev-ok). Die Mandant-Detail-Seite (`MandantDetailPage`) ist nicht betroffen
— sie adressiert den Mandanten explizit über die URL
(`/api/admin/mandants/{id}/teams`).

**Keine Isolationslücke:** Jeder Write wird backend-seitig gegen den
host-abgeleiteten aktuellen Mandanten validiert
(`ResolvesAdminTeamScope::assertTeamOfMandant()`, Cross-Mandant-IDs → 404).
Eine falsche Team-Vorauswahl führt also zu verwirrender UX (404 beim Speichern
gegen fremden Kontext), nie zu Daten in einem anderen Mandanten.

**Einordnung & Lösungspfad:** Kein Defekt, sondern eine dokumentierte
Einschränkung bis zur späteren Multi-Domain-Admin-UX-Phase. Die saubere
Lösung — Domain→Mandant-Auflösung auch für die Admin-Routen des SPA nutzen
(der Host liegt vor, das Backend liefert die Auflösung bereits korrekt) —
erfolgt in dieser späteren Phase. Bis dahin verwaltet ein `super_admin`
Nicht-Primär-Mandanten über die Mandant-CRUD-Oberfläche
(`/admin/mandants/{id}`), die den Mandanten ebenfalls explizit adressiert.

Gleiche Kategorie bewusst akzeptierter UI-Approximationen wie der
`is_team_override`-Flag (siehe oben).

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
