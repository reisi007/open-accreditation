# 04 — Media Self-Service (P8b): Logo/Header

SOLL-Zustand der mandantenselbstverwalteten Logo/Header-Verwaltung. Umsetzung:
`backend/config/permissions.php` (Permission `mandant.media.manage`),
`backend/routes/api.php` (Self-Service-Routen), Controller
`backend/app/Http/Controllers/Api/MandantMediaSelfServiceController.php`, Tests
`backend/tests/Feature/MandantMediaSelfServiceTest.php` +
`backend/tests/Feature/RolePermissionTest.php`.

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

## Sicherheit

- **Kein IDOR:** Der Ziel-Mandant wird **ausschließlich aus dem
  `MandantContext` abgeleitet** (`MandantContext::current() ?? default()`),
  nie aus einem Request-Parameter oder Route-Binding. Ein `mandant_admin` von
  Mandant A bekommt **403**, sobald der aktuelle Kontext ein fremder Mandant B
  ist (Gate: `hasPermission('mandant.media.manage')` ist für B false).
  Der fremde Admin kann damit die Bilder fremder Mandanten weder lesen,
  überschreiben noch löschen.
- **Auth-gated Delivery:** Logo/Header liegen auf der **`private`-Disk** und
  werden nur durch diese authentifizierten Routen gestreamt — niemals als
  öffentliche URLs. Die öffentliche Auslieferung läuft separat über
  `/api/portal/mandant/logo|header` (PortalMediaController).
- **Fehlender Mandant im Kontext** (kein `current()`, kein Primary/Fallback) →
  **404** `{message: 'Kein Mandant im Kontext.'}` (nur für den Gate-Bypasser,
  i. d. R. `super_admin`, erreichbar).
- **Dateityp-/Größen-Grenzen** werden server-autoritativ durchgesetzt
  (Laravel-Rules + Dimensions-Check im Service), nicht im Client.

## Portabilität

Keine Schema-Änderung (nutzt `mandants.logo_path`/`header_path`), kein
PG-spezifisches SQL. Tests laufen auf SQLite `:memory:`.

## Offene Punkte

- `MandantResource`-URL auf die Admin-Delivery-Route zeigen lassen oder eigene
  Self-Service-Delivery-URL reflektieren (Frontend-Nutzung P8b) — Abstimmung
  mit dem Frontend-Batch.
- `features/README.md`-Index um `04-media-self-service.md` ergänzen (außerhalb
  des Backend-Batches).
