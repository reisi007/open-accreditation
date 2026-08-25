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
