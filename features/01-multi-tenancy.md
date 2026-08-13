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
