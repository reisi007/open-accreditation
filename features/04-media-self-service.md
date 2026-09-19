# 04 — Media Self-Service (P8b/W2/W4): Logo/Header, Team- und Event-Typ-Logos

SOLL-Zustand der selbstverwalteten öffentlichen Brand-/Logo-Medien. Umsetzung:
`backend/config/permissions.php` (Permissions `mandant.media.manage`,
`teams.media.manage`), `backend/routes/api.php` (Self-Service-/Admin-Routen),
Controller `MandantMediaSelfServiceController`, `MandantMediaController`,
`TeamController`, `EventTypeController`; Services `MandantMediaService`,
`TeamMediaService`, `EventTypeMediaService`, `BadgeImageService`; Speicher-/
Pfad-Layer `MediaStorage`, `MediaHostResolver`, `MediaPathService`,
`ImageUploadRules`. Pfad-Layout und Fallback-Kette:
`features/media-domain-layout.md`; Brand-Datei-Satz/Caddy:
`features/03-caddy-brand-files.md`.

Tests: `backend/tests/Feature/MandantMediaSelfServiceTest.php`,
`RolePermissionTest.php` (Mandant) sowie
`AdminTeamParticipationTest.php`/`AdminEventTypeTest.php` (Team-/Event-Typ-Logos).

## Rollen-Modell

- **`mandant_admin`** verwaltet das Logo und Header-Bild **seines eigenen
  Mandanten** über das Self-Service-Surface `/api/mandant/logo|header`
  (Permission `mandant.media.manage`, mandantenscoped via
  `User::hasPermission()` + MandantContext-Gate).
- **`super_admin`** behält die volle Kontrolle über **alle** Mandanten über die
  bestehende Admin-Oberfläche (`/api/admin/mandants/{mandant}/logo|header`,
  `mandants.manage`). Die Self-Service-Routen funktionieren für ihn ebenfalls
  (globaler Bypass), auf den aktuellen bzw. primären Mandant.
- **`team_admin`, `user`, `verifier`** besitzen hier **keine** Permission —
  alle sechs Self-Service-Routen liefern 403.
- Gäste (nicht authentifiziert) erhalten **401** (`auth:api`).

## Endpunkt-Vertrag

Basis: `/api/mandant`, alle Routen hinter `auth:api` + `can:mandant.media.manage`
(`name`-Präfix `api.mandant.`).

| Methode | URI            | Route-Name        | Aktion                                            | Antworten |
|---------|----------------|-------------------|---------------------------------------------------|-----------|
| GET     | `/logo`        | `api.mandant.logo`       | Eigene Logo-Datei inline streamen (`private`-Disk) | 200 `image/*` · 404 `{message}` |
| POST    | `/logo`        | `api.mandant.logo.store` | Logo hochladen/ersetzen                            | 200 `MandantResource` (`logo_url`) · 422 |
| DELETE  | `/logo`        | `api.mandant.logo.destroy` | Logo löschen                                       | 204 |
| GET     | `/header`      | `api.mandant.header`     | Eigene Header-Datei inline streamen               | 200 `image/*` · 404 `{message}` |
| POST    | `/header`      | `api.mandant.header.store` | Header hochladen/ersetzen                          | 200 `MandantResource` (`header_url`) · 422 |
| DELETE  | `/header`      | `api.mandant.header.destroy` | Header löschen                                     | 204 |

### Upload-Validierung

- `file` ist `required`, `image`, MIME `jpeg/png/webp`, max. **2048 KB**
  (identisch zur Admin-Oberfläche, `MandantMediaController`).
- Zusätzlich serverseitig (Service `MandantMediaService`):
  **max. 2000×2000 Pixel** (`MAX_IMAGE_DIMENSION`) — Überschreitung → 422.
- Dateiendung wird aus dem **validierten MIME-Typ** abgeleitet (nie aus dem
  Client-Dateinamen).

### Response `MandantResource`

`POST` antwortet mit der frischen Mandant-Repräsentation: `logo_url`/`header_url`
sind `null`, solange kein Bild hinterlegt ist, sonst der Delivery-URL.
(Note: Der Resource-URL zeigt auf die Admin-Delivery-Route
`api.admin.mandants.logo|header` — siehe Befund in `AGENTS.todo.md`, P8b.)

## Team-Logo & Event-Typ-Logo (W2/W4)

Diese Oberflächen liegen unter dem auth-gated Admin-Surface (`/api/admin`), nicht
unter `/api/mandant`. Sie folgen demselben Upload-Kontrakt wie das Mandant-Brand
(`image`, MIME `jpeg/png/webp`, max. 2048 KB, 2000×2000 px, Endung aus validiertem
MIME-Typ).

### Team-Logo (W4)

Basis: `/api/admin`, `auth:api`; Team über mandantenscoped Route-Binding
(fremder Mandant → 404).

| Methode | URI | Gate | Aktion | Antworten |
|---|---|---|---|---|
| GET | `/teams/{team}/logo` | `can:teams.view` | Logo inline streamen | 200 `image/*` · 404 `{message}` |
| POST | `/teams/{team}/logo` | `can:teams.media.manage` + `throttle:admin` | Logo hochladen/ersetzen | 200 `TeamResource` · 403 · 404 · 422 |
| DELETE | `/teams/{team}/logo` | `can:teams.media.manage` + `throttle:admin` | Logo löschen | 204 · 403 · 404 |

**Hierarchie `teams.media.manage` (W4-F1):**

- `super_admin` — globaler Bypass über alle Mandanten.
- `mandant_admin` — jedes Team **seines eigenen Mandanten** (Route-Binding
  scoped den Mandanten; fremdes Team → 404).
- `team_admin` — **nur sein eigenes Team** (`role_user.team_id`); Geschwister-Team
  → **403**. Die Route-Gate-Prüfung wird im `TeamController::authorizeLogoWrite()`
  erneut durchgesetzt.
- `user`, `verifier` — keine Permission → **403** am Route-Gate.

Read (`showLogo`) folgt bewusst dem breiteren `teams.view` (auth-gated inline); die
Schreib-Hierarchie bleibt davon getrennt. Datei-Layout:
`<domain>/teams/<slug>/logo.<ext>` bzw. `_tenants/<id>/teams/<slug>/…`. Ein
Slug-Wechsel verschiebt die Datei über `TeamMediaService::moveForSlugChange` und
schreibt den Pfad neu.

### Event-Typ-Logo (W2)

Basis: `/api/admin`, `auth:api`, Route-Gruppe `can:events.manage`; Event-Typ über
mandantenscoped Binding.

| Methode | URI | Gate | Aktion | Antworten |
|---|---|---|---|---|
| GET | `/event-types/{eventType}/logo` | `can:events.manage` | Logo inline streamen | 200 `image/*` · 404 |
| POST | `/event-types/{eventType}/logo` | `can:events.manage` + `throttle:admin` | Logo hochladen/ersetzen | 200 `EventTypeResource` · 403 · 404 · 422 |
| DELETE | `/event-types/{eventType}/logo` | `can:events.manage` + `throttle:admin` | Logo löschen | 204 · 403 · 404 |

**Schreib-Hierarchie ist strenger als beim Team:** `team_admin` hält zwar
`events.manage` (Route-Gate), `EventTypeController::assertMayWrite()` verweigert
ihm aber das Schreiben → **403**. Es schreiben nur `super_admin` und
`mandant_admin`. Datei-Layout:
`<domain>/event-types/<slug>/logo.<ext>` bzw. `_tenants/<id>/event-types/<slug>/…`;
Slug-Wechsel über `EventTypeMediaService::moveForSlugChange`.

## Service-Extraktion

Die Controller sind dünn; Storage-/Pfad-Regeln liegen in den Services, der
Pfad-Vertrag allein in `MediaPathService`.

| Service | Pfad-Builder | Ablage |
|---|---|---|
| `MandantMediaService` | `domainFile()` | `<domain>/logo.<ext>`, `<domain>/header.<ext>` bzw. `_tenants/<id>/…` |
| `TeamMediaService` | `teamFile()` | `<domain>/teams/<slug>/logo.<ext>` bzw. `_tenants/<id>/teams/<slug>/…` |
| `EventTypeMediaService` | `eventTypeFile()` | `<domain>/event-types/<slug>/logo.<ext>` bzw. `_tenants/<id>/event-types/<slug>/…` |
| `BadgeImageService` | `badgeFile()` | `<domain>/badges/<ulid>.<ext>` bzw. `_tenants/<id>/badges/…` |

Gemeinsame Basis:

- `MediaPathService` — reiner Pfad-Vertrag (Host-Normalisierung, Traversal-Abwehr,
  `_tenants`-Reservierung).
- `MediaHostResolver` — erste Mandant-Domain als Primär-Host (`null` ohne Domain).
- `MediaStorage` — Schreiben immer auf `media`; Lesen `media` → `private`;
  Löschen auf beiden Disks (idempotent). Ein Schreibfehler (`false`) bricht laut
  ab, **bevor** die DB umgeschrieben oder die Vorgängerdatei gelöscht wird.
- `ImageUploadRules` — gemeinsamer Upload-Kontrakt (MIME→Endung, Dimensionslimit).

## Legacy-Lesbarkeit

Vor W6 geschriebene Dateien liegen auf der `private`-Disk im alten Layout
(`mandants/{slug}/logo|header.<ext>` für Brand, `badge-images/{slug}/{ulid}.<ext>`
für Badges). `MediaStorage` liest transparent zuerst `media`, dann `private`, und
`sanitizeFileName`-basierte Replace/Delete-Pfade räumen beide Disks, sodass
un-migrierte Daten bis zum Backfill weiter funktionieren.

Der Backfill `php artisan media:migrate-to-domain-layout` ist **dry-run per
Default**, idempotent und deckt ausschließlich die `private`-Legacy-Pfade
(`mandants/…`, `badge-images/…`) ab. Host-neutrale `_tenants/…`-Dateien werden
nicht umgezogen (sie liegen bereits auf `media`), Team-/Event-Typ-Logos existieren
erst seit W2/W4 und damit nie im alten Layout. Details:
`features/media-domain-layout.md`.

Personenbilder (`user-media/*`) bleiben unberührt auf der `private`-Disk und
auth-gated.

## Sicherheit

- **Kein IDOR:** Der Ziel-Mandant wird **ausschließlich aus dem
  `MandantContext` abgeleitet** (`MandantContext::current() ?? default()`),
  nie aus einem Request-Parameter oder Route-Binding. Ein `mandant_admin` von
  Mandant A bekommt **403**, sobald der aktuelle Kontext ein fremder Mandant B
  ist (Gate: `hasPermission('mandant.media.manage')` ist für B false).
  Der fremde Admin kann damit die Bilder fremder Mandanten weder lesen,
  überschreiben noch löschen.
- **Auth-gated Delivery vs. öffentliche Auslieferung:** Logo/Header liegen im
  W1-Layout auf der **`media`-Disk** und werden durch die authentifizierten
  Admin-/Self-Service-Routen gestreamt. Die **einzige öffentliche URL** ist die
  auth-freie Portal-Delivery `/api/portal/mandant/logo|header`
  (`PortalMediaController`); nach Go-Live kommt der Caddy-Direct-Serve aus
  `MEDIA_ROOT` hinzu (`features/media-domain-layout.md`). Legacy-Dateien auf
  `private` bleiben bis zum Backfill über `MediaStorage` lesbar.
- **Fehlender Mandant im Kontext** (kein `current()`, kein Primary/Fallback) →
  **404** `{message: 'Kein Mandant im Kontext.'}` (nur für den Gate-Bypasser,
  i. d. R. `super_admin`, erreichbar).
- **Dateityp-/Größen-Grenzen** werden server-autoritativ durchgesetzt
  (Laravel-Rules + Dimensions-Check im Service), nicht im Client.

## Portabilität

Keine Schema-Änderung durch die Media-Services selbst (nutzt
`mandants.logo_path`/`header_path`, `teams.logo_path`,
`event_types.logo_path`, `badge_images.path`); kein PG-spezifisches SQL. Das
Pfad-/Storage-Layer ist reine PHP-/Query-Builder-Logik. Tests laufen auf SQLite
`:memory:`.

## Offene Punkte

- `MandantResource`-URL auf die Admin-Delivery-Route zeigen lassen oder eigene
  Self-Service-Delivery-URL reflektieren (Frontend-Nutzung P8b) — Abstimmung
  mit dem Frontend-Batch.
