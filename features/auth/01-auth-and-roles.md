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
     `activation_token` = **sha256-Digest** des rohen Tokens (F4; 64 Hex, passt
     in die 64-Zeichen-Spalte) und `activation_token_expires_at`
     (TTL **24 h**, `AuthController::ACTIVATION_TTL_HOURS`). Der rohe
     `Str::random(64)`-Token landet NUR im Mail-Link — die DB speichert nie
     den Klartext-Token.
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
| `mandant_admin` | ein Mandant (Verband) | verwaltet Kategorien, Events, Benutzer und Akkreditierungen innerhalb seines Mandants (Mandant/Teams selbst: super_admin-only) |
| `team_admin` | Mandant + Team (`team_id`) | **team_id-FK folgt P2**; read-only-Sicht auf Verbands-Akkreditierungen eigener Personen (D7, P2/P3) |
| `user` | ein Mandant | regulärer Akkreditierter (Default-Rolle bei Registrierung) |
| `verifier` | ein Mandant | Ordner/Check-in an Events |

Model-Helfer am `User`: `isSuperAdmin()`, `isMandantAdmin($mandantId)`,
`isTeamAdmin($teamId)`, `isVerifier($mandantId)`, `roleForMandant($mandantId)`,
`hasRole($slug, $mandantId, $teamId)` (Null-Scope matcht globale
super_admin-Zeile). `RoleSeeder`/`DatabaseSeeder` sind idempotent; Admin wird
als globaler super_admin angelegt (`ADMIN_EMAIL`/`ADMIN_PASSWORD`).

## Autorisierung / Gates (P1d)

Zentrale **Rollen→Permission-Matrix** in `backend/config/permissions.php`
(Single Source of Truth). Jede Permission wird in
`backend/app/Providers/AuthServiceProvider::boot()` als **Gate** registriert;
die Scope-Logik lebt in `User::hasPermission()` (Matrix + Mandant-/Team-Scope).

| Rolle | Permissions | Scope |
|---|---|---|
| `super_admin` | `*` (global, `Gate::before` → `true`) | beliebiger/kein Mandant |
| `mandant_admin` | `categories.manage`, `events.manage`, `users.manage`, `accreditations.view`, `accreditations.manage` | aktueller Mandant (`MandantContext`) |
| `team_admin` | `teams.manage`, `events.manage`, `accreditations.manage`, `accreditations.view` (read-only, D7) | eigenes Team (`role_user.team_id`) |
| `user` | `accreditations.self` | aktueller Mandant |
| `verifier` | `verification.verify` | aktueller Mandant |

Semantik:

- **Cross-Mandant deny:** Keine Rolle im aktuellen Mandanten
  (`roleForMandant()` → null) → alle Gates `false`. Nur `super_admin` ist
  global (`Gate::before` → `true`), auch ohne gesetzten Mandanten. Gäste und
  User ohne Rolle → `false`.
- **Team-Scope (team_admin):** Gates akzeptieren optional eine `team_id` als
  zweites Argument (z. B. `Gate::authorize('events.manage', $teamId)`). Eine
  fremde `team_id` → deny; ohne Argument wird das Team der Rolle
  (`role_user.team_id`) verwendet. Team ohne Team-Zuordnung (P2) → deny.
  mandant_admin/`user`/`verifier` ignorieren das `team_id`-Argument (ihr Scope
  ist der gesamte Mandant).
- **D7 (P2/P3):** `accreditations.view` für `team_admin` ist vorbereitet —
  read-only-Sicht auf Verbands-Akkreditierungen eigener Personen. Die
  Personen-Scope-Filterung folgt mit den echten Ressourcen in P3; hier ist nur
  die Gate-Semantik festgenagelt (Permission vorhanden, Rolle gültig, Team
  validiert).
- `mandants.manage`/`teams.manage` sind **super_admin-only** — Mandanten und
  Teams verwaltet der Super Admin, nicht der Mandant-Admin (D2/Portal-Muster).
- Nutzung in Controllern/Policies (P2+): `Gate::allows()`/`Gate::authorize()`
  oder direkt `$user->hasPermission($permission, $mandantId, $teamId)`.

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

## Hardening (P7 — erledigt 2026-08-14)

- **F1 (erledigt):** Aktivierungslink aus der Mandanten-Domain statt
  `config('app.url')` — im Ist umgesetzt (`activationUrl`-Fallback-Chain,
  F1-Fix 2026-08-13).
- **F2 (erledigt):** JWT-Parser-Kette auf den **httpOnly-Cookie `accr_jwt`
  beschränkt** (`AppServiceProvider::boot()` → `setChain([Cookies])`).
  `Authorization: Bearer`, `?token=`, POST `token` und Route-Param-Tokens
  werden NICHT mehr akzeptiert — jeder andere Kanal ist tote Fläche für
  Token-Exfiltration. (Registriert in `AppServiceProvider::boot()`, da dieser
  Provider nach den Package-Discovery-Providern bootet und die vom Package
  aufgebaute Kette damit vollständig ersetzt.)
- **F3 (erledigt):** `local`-Disk hat `serve => false` — User-/Mandanten-Media
  liegen auf Disk `private` und werden ausschließlich über die auth-gated
  Endpoints ausgeliefert (`config/filesystems.php`).
- **F4 (erledigt):** `activation_token` wird als **sha256-Digest** (64 Hex)
  gespeichert; der **rohe Token** steht nur im Mail-Link. Lookup in
  `activate()` hashed den eingehenden Token (`AuthController::register/activate`).
- **F5 (erledigt):** Upload-Kontingent + Rate-Limit für `/api/user/media`:
  `throttle:media` (30/min pro User, `media:{userId|ip}`) auf die
  User-Media- und Mandant-Self-Service-Uploads (P8b), plus
  `UserMediaService`-Quota: **max. 10 Dateien** (`MAX_MEDIA_FILES`) und
  **max. 10 MiB** (`MAX_MEDIA_BYTES`) pro User — singular-Ersatz zählt nicht
  doppelt; Verstoß → 422 mit deutscher Meldung.
- **B3 (erledigt):** `trustHosts`-Allow-List aus `mandant_domains.hostname`
  + lokale Defaults (`localhost`, `127.0.0.1`, `^\[::1\]$`, `^(.+\.)?test$`,
  `^(.+\.)?localhost$`) in `bootstrap/app.php`. Fremde Hosts → **400** vor der
  Mandant-Auflösung; allow-listete, aber unbekannte Hosts weiterhin **404**
  (MandantContextMiddleware). Callback defensiv (DB nicht verfügbar → nur
  Defaults).
- **P1a-B1 (erledigt):** `MandantContext::resolve()` cached unbekannte Hosts
  **negativ** (Sentinel `MISSING`, TTL 60 s) — Host-Request-Floods auf
  Bogon-Domains treffen die `mandant_domains`-Tabelle nicht mehr;
  `forgetHost()` räumt beide Einträge (gleicher Cache-Key).
- **P1a-B2 (erledigt):** Referer-Fallback in `MandantContextMiddleware` nur
  noch für die **Vite-Dev-Origin `localhost:5173`** — ein spoofbarer
  Fremd-Referer steuert die Host-Auflösung nicht mehr.
- **P2a-RL (erledigt):** `throttle:admin` (300/min, `admin:{userId|ip}`) auf
  allen **schreibenden** Admin-Routen (`POST/PUT/DELETE` unter
  `/api/admin/*`); Admin-GET-/Read-Routen bewusst unlimitiert.
- **P5-F2 (erledigt):** `throttle:resend` (10/min, `resend:{userId|ip}`) auf
  `POST /api/admin/applications/{application}/resend` — Mail-Spam-Vektor zu.
- **P0-Fix-F3 (erledigt):** `DatabaseSeeder` erzeugt den Default-Admin in
  **Production nur mit explizit gesetzten `ADMIN_EMAIL` + `ADMIN_PASSWORD`**
  und verweigert das Standard-Passwort `admin` hard; sonst Skip mit Log.
  Local/Testing unverändert.
- **Neue Limiters keyen per `$request->user('api')`:** `Request::user()`
  löst den Default-Guard (web/session) auf und ist für API-Requests `null` —
  `media`/`admin`/`resend` müssen daher explizit den `api`-Guard lesen.
  (Hinweis: der bestehende `apply`-Limiter nutzt noch `$request->user()` und
  fällt damit faktisch auf per-IP zurück — bewusst nicht geändert, siehe
  Befund im Review.)

Akzeptierte Rest-Risiken:
- F6 (info): 403-Texte offenbaren bewusst die Kontoexistenz (akzeptiert).
- F7 (info): Mandant-Check nur beim Login — Ressourcen-Scoping (P2).
