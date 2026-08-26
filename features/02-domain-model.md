# 02 — Domain-Model

## SOLL — Entity-Übersicht

Hierarchie: **Mandant (Verband) → Team (Verein, optional) → Kategorie →
Akkreditierung (Quota + Frist) → Application → Sub-Akkreditierung.**

## P1 — Im Ist umgesetzt

| Entity | Spalten (Auszug) | Anmerkung |
|---|---|---|
| `mandants` | `slug` (unique), `name`, `logo_path`, `header_path`, `impressum_text`, `privacy_text`, `smtp_config` (json), `teams_enabled` (bool, default false), `is_primary`, `is_active`, timestamps | Verband, eigene Domain, kein Theme. `smtp_config` als JSON (portabel, P5). |
| `mandant_domains` | `id`, `mandant_id` (FK, cascade, indexed), `hostname` (unique), timestamps | Hostname dient als Lookup-Index für `MandantContext::resolve()`; unique constraint = Suchindex. |
| `users` | `name`, `email` (unique), `password` (hashed), `email_verified_at`, **Profil-Felder** `title/gender/birth_date/street/zip/city/country/company/phone/fax/branch/position/vest_available/vest_number`, **Aktivierung** `activation_token` (64, unique, nullable), `activation_token_expires_at`, `rememberToken`, timestamps | `branch` als Enum-Werte in der API (`print/tv/online/radio/photo/other`). Konto gehört genau einem Mandanten (über `role_user.mandant_id`), kein eigenes `mandant_id`-Feld am User. |
| `roles` | `id`, `name`, `slug` (unique), `description`, timestamps | Fünf Rollen, Slug ist Source of Truth (`app/Enums/UserRole.php`). |
| `role_user` | `id`, `user_id` (FK, cascade), `role_id` (FK, cascade), `mandant_id` (FK nullable, cascade), `team_id` (unsignedBigInteger nullable, **kein FK**), `unique(['user_id','role_id','mandant_id','team_id'])`, timestamps | Pivot mit Scope. `mandant_id = NULL` = globaler `super_admin`. **`team_id`-FK folgt P2** (Teams-Tabelle existiert noch nicht — plain nullable Integer). |
| `user_media` | `id`, `user_id` (FK, cascade), `type`, `path`, `mime`, `size`, `original_name`, timestamps; Index `[user_id, type]` | Private (auth-gated) Fotos/Anhänge auf Disk `private` (`storage/app/private`). `type` ∈ `portrait/press_id/attachment`; `portrait`/`press_id` singular (ersetzen), `attachment` mehrwertig. `path` wird nie als Public-URL exponiert. |

## API-Vertrag (Ist P1, Auszug)

- `GET /api/user/media/{media}` — auth-gated Delivery, **Owner-only** (403
  für Fremde), Disk `private`, `Content-Type` aus `user_media.mime`.
- `PUT /api/user/profile` — nur eigene Profil-Felder (keine User-ID im
  Request → kein Cross-User-Write).
- Details in `features/auth/01-auth-and-roles.md`.

## P2/P3 — Ausblick (noch nicht umgesetzt)

| Entity | Beschreibung |
|---|---|
| `teams` | Vereine (optional je Mandant, `teams_enabled`), Heimstätte (Default-Ort), Kategorie-Overrides; `role_user.team_id` bekommt dann den FK |
| `categories` | z. B. Presse, Fotograf, Delegation; erbt vom Mandant, Team überschreibt |
| `events` | Titel, Datum, Ort (Default = Heimstätte, überschreibbar), Wettbewerb, Frist (Default/Override); Ebene Mandant oder Team |
| `accreditations` | Kategorie + Event/Scope, Quota, Frist, VIP/Blacklist-Konfiguration |
| `applications` | Antrag: Kategorie/Scope, Status `requested/approved/denied/blacklisted`, Foto/Anhänge. Status-Set dauerhaft: die Engine setzt **nie** `blacklisted` (nutzt `denied` + `reason`; der `blacklisted`-Status ist für die Blacklist-Verwaltung in P3e reserviert) — Details `accreditation/01-allocation-engine.md` |
| `sub_accreditations` | Park-/Sitzkarte, nur bei Haupt-Akkreditierung, eigenes Kontingent, auto/manuell |
| `badge_templates` | Ausweis-Layout (Feld-Set, Positionen, Logo/Header/Farben) |
| `blacklists` | Gesperrte Personen + Domänen (Block auf Mandant-Ebene); Enforcement in `accreditation/01-allocation-engine.md` |
| `wallet_passes` | Apple/Google Wallet (PKPASS) je Akkreditierung |

## Anmelde-Scopes

Event/Spiel · Liga-weit · Saison (Verein) · Pro-Spiel.

## Portabilitätsregel

Schema/Queries bleiben zwischen **Postgres (Dev/Prod)** und **SQLite
`:memory:` (Tests)** portabel: kein PG-spezifisches SQL in Migrationen/Queries,
JSON-Spalten via Laravel `json`-Type, Datumsarithmetik über Query-Builder/
Eloquent. Wo Postgres-Features nötig sind → Service-Abstraktion + separater
Integrationstest.

## P2-F2 — User-Suche: akzeptiertes Risiko (kein Index bei führendem Wildcard)

Die Admin-User-Suche filtert mit `LOWER(users.name) LIKE '%term%'` (führendes
Wildcard). Ein B-Tree-Index — auch funktional auf `LOWER(name)` — kann eine
`LIKE`-Prädikat mit führendem `%` weder in SQLite noch in Postgres bedienen:
beide Engines sequenz-scannen die Tabelle. Ein Trigram-/GIN-Index wäre
rein PG-only und verletzt die Portabilitätsregel (§2).

**Akzeptiertes Risiko:** Der Index wurde bewusst aus der `users`-Migration
entfernt — er würde nur Write-Amplifikation erzeugen, ohne die Ziel-Query
zu beschleunigen. Bei wachsendem Bestand (>10⁵ User/Mandant) ist ein
dedizierter Suchservice (z. B. Meilisearch/Elasticsearch) oder ein
PG-only Trigram-Index (mit Service-Abstraktion + separatem
Integrationstest) vorzusehen. Bis dahin ist der Seq-Scan tragbar
(Users/Mandant ist klein, Suche ist Admin-only).

## P2-F1 — User-Suche: Non-ASCII-Divergenz (akzeptiertes Risiko)

`LOWER()` in SQLite faltet **nur ASCII** (`A-Z` → `a-z`); nicht-lateinische
Zeichen wie `Ü`, `Ö`, `Ä`, `ß` bleiben unverändert. Postgres `LOWER()` ist
Unicode-aware und faltet auch Non-ASCII. Die portable `LOWER(col) LIKE
LOWER(?)`-Kontrakt (CC-R1) ist daher für ASCII-Terme identisch, divergiert
aber bei Non-ASCII:

- SQLite: Suche nach `müller` matcht **nicht** `MÜLLER` (weil `LOWER('Ü') = 'Ü'`).
- Postgres: Suche nach `müller` matcht `MÜLLER` (weil `LOWER('Ü') = 'ü'`).

**Akzeptiertes Risiko:** Die SQLite-Testsuite (PHPUnit, `SQLite :memory:`)
validiert daher den ASCII-Pfad; der Non-ASCII-Pfad ist gegen Postgres
manuell/integrationstest zu prüfen. Für die anfängliche Admin-Suche (kleine
User-Zahlen/Mandant, ASCII-Domänen) tragbar. Sauberer Fix: `mb_strtolower()`
im Service-Layer (PHP-seitig, engine-unabhängig) — folgt später, wird hier
als Portabilitäts-Trade-off dokumentiert.

## Applications: `created_at` ist der Antragszeitpunkt (kein `applied_at`, P3b-F2)

Die Tabelle `applications` besitzt **kein** eigenes `applied_at`-Feld — der
Antragszeitpunkt **ist** `created_at` (Laravel-Timestamps): beim Anlegen des
Antrags (`POST …/apply`) gesetzt und danach unveränderlich; Statuswechsel der
Engine/Admin-Aktionen schreiben nur `status`/`reason` (und `updated_at`
als Nebenprodukt), nie `created_at`.

- **API-Vertrag:** Exponiert wird ausschließlich **`created_at`**
  (ISO-8601, `ApplicationResource`). Ein Feld `applied_at` existiert weder im
  Schema noch in der API — Client-Seite darf sich darauf nicht stützen.
- **FCFS-Ordering:** Die Allocation-Engine nutzt `created_at ASC` als
  Eingangsreihenfolge (= Antragseingang), siehe
  `accreditation/01-allocation-engine.md`.
- **Kein Decision-Zeitstempel:** Der Freigabe-/Ablehnungszeitpunkt wird
  aktuell **nicht** persistiert oder exponiert (`updated_at` ist kein
  verlässlicher Ersatz — z. B. ändert eine spätere VIP-Setzung via
  `setPriority` den Wert ohne Statuswechsel). Wird ein Audit-Zeitstempel für
  die Freigabe-Entscheidung benötigt → eigene neue Spalte (SOLL, P3e-Cleanup),
  kein Überladen von `created_at`.

## `is_team_override` Semantik (P2b-F5)

`CategoryResource` exponiert einen abgeleiteten Boolean **`is_team_override`** —
kein DB-Feld, definiert als `team_id !== null`
(`backend/app/Http/Resources/CategoryResource.php`). Er markiert jede
Kategorie-Zeile auf **Team-Ebene** (Verein), unabhängig davon, ob fachlich
ein Verbands-Datensatz mit gleichem Slug tatsächlich „überschrieben" wird.

- **Semantik-Kosmetik (bewusst akzeptiert):** Das „Team-Override"-Badge in der
  Admin-UI (`frontend/src/pages/admin/CategoriesPage.tsx`) erscheint auf
  **jeder** Team-Kategorie — auch wenn es streng genommen nur ein
  „Team-Level"-Flag ist. Der Flag darf nicht als Nachweis eines echten
  Slug-Overrides missdeutet werden.
- **UI-Konsequenz:** Team-Kategorien werden visuell als Override gekennzeichnet,
  obwohl sie ggf. nur eine erste Team-Level-Instanz sind. Kein Schema-/API-
  Change nötig; Details in `features/01-multi-tenancy.md`.
