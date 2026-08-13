# Auth & Rollen (P1)

SOLL-Zustand des Auth-/Rollen- und Profil-/Media-Systems (P1). Umsetzung:
`backend/app/Http/Controllers/Api/AuthController.php`,
`backend/app/Http/Controllers/Api/ProfileController.php`,
`backend/app/Http/Controllers/Api/UserMediaController.php`,
`backend/app/Services/UserMediaService.php`, `backend/app/Models/User.php`,
`backend/app/Enums/UserRole.php`, `backend/app/Enums/MediaType.php`,
`backend/app/Http/Resources/UserResource.php`,
`backend/app/Http/Resources/UserMediaResource.php`,
`backend/app/Mail/ActivationMail.php`.

## Auth-Flow

1. **Registrierung** `POST /api/auth/register` (throttle `5,1`)
   - Felder: `name`, `email` (unique, lowercase), `password` (min 8,
     `confirmed`).
   - Erzeugt den User mit Rolle **`user`** scoped auf den aktuellen Mandanten
     (`role_user.mandant_id`), `email_verified_at = null`, plus
     `activation_token` (`Str::random(64)`) und `activation_token_expires_at`
     (TTL **24 h**, `AuthController::ACTIVATION_TTL_HOURS`).
   - Versendet die **Aktivierungsmail** (`ActivationMail`, Markdown-Template
     `mail.activation`). Der Aktivierungslink wird aus der **Mandanten-Domain**
     gebaut (Fallback-Chain: erste Domain des aktuellen Mandanten →
     `config('app.url')`-Host → Request-Host; Schema aus `app.url`), damit der
     Cross-Mandant-Login nicht auf eine fremde Domain zeigt.
   - Ohne aufgelösten Mandanten (unbekannte Domain) → **422**.
2. **Aktivierung** `GET /api/auth/activate/{token}` (throttle `20,1`, GET
   damit der Mail-Link direkt im Browser funktioniert)
   - Token unbekannt → **404**; abgelaufen (Expiry null/past) → **410**.
   - Erfolg: `email_verified_at = now()`, **Token wird verbraucht**
     (`activation_token = null`, `activation_token_expires_at = null`).
   - Erst danach ist das Konto login-fähig.
3. **Login** `POST /api/auth/login` (throttle `5,1`)
   - Validierung `email` + `password`. Falsche Zugangsdaten → **401** (gleiche
     Antwort, ob Konto existiert oder nicht — bewusst).
   - Nicht aktiviert → **403**.
   - **Cross-Mandant-Isolation:** Keine Rolle für den aktuellen Mandanten →
     **403** („Account ist für dieses Portal nicht registriert"). Ausnahmen:
     globaler `super_admin` (überall erlaubt) und Requests ohne Mandant
     (console/testing).
   - Erfolg: **JWT** in httpOnly-Cookie **`accr_jwt`** — `SameSite=Lax`,
     `secure` außer `local`, Cookie-TTL = JWT-TTL (`config/jwt.php` TTL),
     Pfad `/`. Token erreicht nie localStorage. Antwort liefert zusätzlich
     `expires_in` (Sekunden).
4. **Logout** `POST /api/auth/logout`
   - JWT wird invalidiert (Blacklist, `jwt.blacklist_enabled = true`),
     Cookie wird via `cookie()->forget()` entfernt.
5. **`/me`** `GET /api/auth/me`
   - `UserResource` mit `user->fresh(['roles', 'media'])`: Kernfelder +
     Profilfelder + Rollen (slug/name/mandant_id/team_id aus Pivot) + Media.
     **Keine Secrets** (`password`, `activation_token` sind `$hidden`;
     Storage-Pfade nie serialisiert).

## Rollen-Matrix

Fünf Rollen, `roles.slug` als Source of Truth
(`backend/app/Enums/UserRole.php`). Scope über Pivot
`role_user.mandant_id`/`role_user.team_id`:

| Rolle | Scope | Bemerkung |
|---|---|---|
| `super_admin` | **global** (`mandant_id = NULL`, `team_id = NULL`) | Plattform-Admin, darf sich auf jeder Mandanten-Domain anmelden |
| `mandant_admin` | ein Mandant (Verband) | verwalten von Mandant/Teams/Kategorien |
| `team_admin` | Mandant + Team (`team_id`) | **team_id-FK folgt P2**; read-only-Sicht auf Verbands-Akkreditierungen eigener Personen (D7, P2/P3) |
| `user` | ein Mandant | regulärer Akkreditierter (Default-Rolle bei Registrierung) |
| `verifier` | ein Mandant | Ordner/Check-in an Events |

Model-Helfer am `User`: `isSuperAdmin()`, `isMandantAdmin($mandantId)`,
`isTeamAdmin($teamId)`, `isVerifier($mandantId)`, `roleForMandant($mandantId)`,
`hasRole($slug, $mandantId, $teamId)` (Null-Scope matcht globale
super_admin-Zeile). `RoleSeeder`/`DatabaseSeeder` sind idempotent; Admin wird
als globaler super_admin angelegt (`ADMIN_EMAIL`/`ADMIN_PASSWORD`).

## Profil

`PUT /api/user/profile` (auth) — nur der authentifizierte User, **keine
User-ID im Request** (kein Cross-User-Write). Felder: `title`, `gender`,
`birth_date` (date, `before:today`), `street`, `zip`, `city`, `country`,
`company`, `phone`, `fax`, `branch` (`Rule::in('print','tv','online','radio',
'photo','other')`), `position`, `vest_available` (bool), `vest_number`.
Antwort: `UserResource` + `message`.

## Media-Vertrag

Endpoints (auth-gated):
- `GET /api/user/media` — eigene Media-Liste.
- `POST /api/user/media` (multipart) — Upload.
- `GET /api/user/media/{media}` — **auth-gated Delivery, Owner-only**
  (Fremde → 403, unbekannte IDs → 404 via Route-Model-Binding). Streamt
  Original-Bytes von Disk `private` mit `Content-Type` aus `user_media.mime`.
- `DELETE /api/user/media/{media}` — Owner-only, Datei + Row.

Upload-Regeln (server-authoritativ, `UserMediaController` + `UserMediaService`):
- `type` ∈ `portrait` | `press_id` | `attachment` (`MediaType`).
- Datei: `image`, `mimes:jpeg,png,webp`, **max 10 MB** (`max:10240` KB).
- **Max. 2000 × 2000 px** (`UserMediaService::MAX_IMAGE_DIMENSION`, via
  `getimagesize` Server-Check; sonst 422).
- `portrait`/`press_id` sind **singular** — neuer Upload ersetzt den
  vorherigen (Datei + Row); `attachment` erlaubt mehrere.
- Storage-Pfad: `user-media/{mandantSlug}/{userId}/{type}/{uuid}.{ext}` auf
  **Disk `private`** (`storage/app/private`) — kein Public-Serving.
- `UserMediaResource` exponiert nur ID/type/mime/size/original_name/created_at
  + auth-gated `url()`; nie den privaten Pfad.

## Hardening / Follow-up (offen, P7)

- **F1 (erledigt):** Aktivierungslink aus der Mandanten-Domain statt
  `config('app.url')` — im Ist umgesetzt (`activationUrl`-Fallback-Chain,
  F1-Fix 2026-08-13).
- **F2 (low):** JWT-Parser-Kette auf **Cookie** beschränken (aktuell auch
  Header/Query/Form möglich).
- **F3 (low):** `local`-Disk hat `serve => true` und teilt Root mit `private`
  — Sicherstellen, dass keine privaten Dateien serviert werden.
- **F4 (low):** `activation_token` als **Hash** (sha256) statt Klartext in
  der DB (Token im Mail-Link bleibt Klartext).
- **F5 (low):** Upload-Kontingent/Rate-Limit für `/api/user/media`.
- F6 (info): 403-Texte offenbaren bewusst die Kontoexistenz (akzeptiert).
- F7 (info): Mandant-Check nur beim Login — Ressourcen-Scoping (P2).
