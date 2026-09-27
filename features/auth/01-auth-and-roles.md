# Auth & Rollen (P1)

SOLL-Zustand des Auth-/Rollen- und Profil-/Media-Systems (P1). Umsetzung:
`backend/app/Http/Middleware/EnsureMandantMembership.php`,
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
   - **SameSite-Begründung (WP-1-a):** SPA und API teilen **eine** Origin —
     die SPA ruft relativ auf (`fetch('/api/…')`,
     `frontend/src/api/client.ts`), und Caddy routet `/api*` im **selben**
     Mandanten-Site-Block zum Backend (`deployment/caddy-*.Caddyfile`).
     `Lax` ist deshalb in **allen** Umgebungen ausreichend und der Default.
     `SameSite=None` (nur zusammen mit `Secure` gültig, und es nimmt die
     SameSite-Hälfte des CSRF-Schutzes) existiert nur als **explizites
     Opt-in** `JWT_CROSS_SITE_COOKIE=true` (`config/jwt.php: cross_site_cookie`)
     für eine wirklich cross-site Deployment; nie aus der Umgebung abgeleitet.
   - **`SameSite=None` ohne `Secure` ist ein totes Cookie** (Chrome ≥ 84 /
     Firefox ≥ 96 verwerfen es ⇒ Auth bricht lautlos). `Controller::
     respondWithToken()` behandelt die beiden Attribute deshalb als gekoppelt:
     Opt-in **aus** ⇒ `Lax` + `secure = ! local` (die Dev-Server laufen über
     Plain-HTTP); Opt-in **an** ⇒ `None` + `Secure` — und die Kombination mit
     `APP_ENV=local` (wo `None` und `Secure` sich ausschließen) wird **laut
     verweigert**: `Log::critical` + `RuntimeException` ⇒ 500, **kein** Cookie.
     Vorher wurde in `local` `SameSite=Lax` **ohne** `Secure` emittiert (in den
     übrigen Umgebungen `SameSite=None` **mit** `Secure`).
   - **Session-Cookie (WP-1-f):** `config/session.php: secure` =
     `env('SESSION_SECURE_COOKIE', APP_ENV !== 'local')` — ohne Default war der
     Wert `null`, der Session-Cookie also in **keiner** Umgebung `Secure`.
4. **Logout** `POST /api/auth/logout`
   - JWT wird invalidiert (Blacklist, `jwt.blacklist_enabled = true`),
     Cookie wird via `cookie()->forget()` entfernt.
5. **`/me`** `GET /api/auth/me`
   - `UserResource` mit `user->fresh(['roles', 'media'])`: Kernfelder +
     Profilfelder + Rollen (slug/name/mandant_id/team_id aus Pivot) + Media.
     **Keine Secrets** (`password`, `activation_token` sind `$hidden`;
     Storage-Pfade nie serialisiert).
6. **Mitgliedschaft pro Request** `EnsureMandantMembership`
   - Der JWT trägt **keinen** Mandanten-Claim (`getJWTCustomClaims()` = `[]`),
     und der Login-Check aus Schritt 3 läuft **einmal**. Ohne zweiten Check ist
     ein auf `a.example` ausgestelltes Token auf `b.example` gültig — und die
     ungegateten Schreibrouten der `auth:api`-Gruppe (`POST
     /accreditations/{id}/apply`, `POST /user/media`, `PUT /user/profile`) haben
     nur ihre **Ressource** mandant-scoped geprüft, nicht die **Identität**: es
     entstanden Anträge im fremden Mandanten (inkl. Porträt/Presse-ID in dessen
     Freigabe-Queue) und Uploads im Storage-Namespace des fremden Mandanten.
   - **Regel:** Für jeden Request unter `auth:api` mit aufgelöstem Mandanten
     muss der authentifizierte User **mindestens eine `role_user`-Zeile für
     diesen Mandanten** haben. Globaler `super_admin` (`mandant_id IS NULL`) ist
     wie beim Login überall erlaubt. Verstöß → **403** mit derselben Meldung wie
     der Login-Fall: „Dieser Account ist für dieses Portal nicht registriert."
     (`EnsureMandantMembership::DENIED_MESSAGE`); der konkrete Grund geht ins
     Log (`Log::notice`), nicht in die Antwort.
   - **Zwei Ausnahmen (`EnsureMandantMembership::EXEMPT_ROUTES`, #6-1-D1):**
     `POST /api/auth/logout` und `GET /api/auth/me` antworten **auch** für ein
     Konto, dem die Rolle **gerade** entzogen wurde. Ohne diese Ausnahme
     blockierte die Middleware genau die Entziehung, die sie auslösen soll: die
     SPA ruft `/auth/me` bei **jedem** Laden und `POST /auth/logout` beim
     Abmelden auf, ein 403 auf beiden lässt den httpOnly-Cookie stehen (nur ein
     **401** löst im Frontend den globalen Logout-Handler aus), und das Konto
     bliebe bis zum Cookie-Ablauf (`JWT_TTL`, 60 min) auf einer Seite, die es
     nicht mehr benutzen darf. Beide Ausnahmen weiten die Mandantengrenze
     **nicht** auf: `/me` liefert ausschließlich den **eigenen**
     `UserResource` (eigene Felder, eigene Rollen, eigene Medien) — niemals
     fremde Mandanten-Daten; `/logout` räumt nur das **eigene** Token und den
     **eigenen** Cookie ab. Beides ist Lesen bzw. Session-Abbau, **kein**
     mandant-scoped Write. Die Ausnahme ist **nicht** rollenbasiert und gilt
     **nur** für genau diese zwei Routen (Match auf Route-**Name** + Methode,
     damit eine künftige Route den Namen nicht erben kann); sie wird erst
     **nach** dem Mitgliedschafts-Test geprüft, kostet Mitglieder also nichts.
   - **Ergänzend, nicht ersetzend:** das per-Ressource-`forMandant()`-Scoping in
     den Controllern bleibt unverändert. Die Middleware beantwortet „darf dieses
     Konto in diesem Mandanten überhaupt handeln?", die Controller „gehört
     DIESE Ressource dem Mandanten/gehört sie mir?".
   - **Warum pro Request statt Claim im JWT:** Ein `mid`-Claim müsste bei jedem
     Rollenwechsel **und** bei jedem Mandantenwechsel neu ausgestellt werden und
     bricht Multi-Mandant-User (Rollen in mehreren Mandanten ⇒ ein Claim
     reicht nicht). Der Pivot wird stattdessen pro Request gelesen: **genau eine**
     Query (`User::isMemberOfMandant()`), dafür greift die Entziehung **sofort**
     — eine in Mandant B entzogene Rolle wirkt im nächsten Request, nicht erst
     nach `JWT_TTL` (60 min). Eine Claim-Optimierung ist bewusst nicht Teil des
     Scopes.
   - **Inert ohne Session:** Routen ohne `auth:api` (öffentliches Portal,
     öffentliche Akkreditierungs-Liste, QR-`verify`, `login`/`register`/
     `activate`) lösen den `api`-Guard nicht auf — `hasUser()` löst ihn *nicht*
     auf, die Middleware stellt in dem Fall **0 Queries** und kann die Antwort
     nicht ändern. Ebenso inert ohne aufgelösten Mandanten (Console/CLI, Tests
     ohne Host) — dieselbe `MandantContext`-Escapetür wie im Login.
   - **Position in der Pipeline:** an die `api`-Gruppe **angehängt** und in der
     Middleware-Priority-Liste direkt **nach `SubstituteBindings`** eingereiht
     (`bootstrap/app.php` ⇒ `appendToPriorityList(SubstituteBindings::class, …)`).
     Dadurch läuft sie als **letzte** Middleware vor der Controller-Action: nach
     `auth:api` (der User muss aufgelöst sein), nach allen route-spezifischen
     Rate-Limitern, vor jeder mandant-bezogenen Mutation. Die Limit-Reihenfolge
     entspricht der des Logins (`throttle:login` → `mayLogInOnCurrentMandant()`);
     ein abgelehnter Cross-Mandant-Request läuft also **innerhalb** des
     `throttle:apply`/`throttle:media`-Budgets, statt eine unbegrenzte 403-Schleife
     zu öffnen. Route-Model-Binding ist zu diesem Zeitpunkt bereits gelaufen —
     es ist ein **mandanten-scoped** `find` (siehe M2), das nichts anlegt oder verändert.
   - **Kosten:** 1 Query pro authentifiziertem Request mit aufgelöstem
     Mandanten (Postgres: `Index Scan using role_user_scope_unique` auf
     `user_id`, `EXISTS`-Zweig auf `roles.slug` wird im Normalfall nicht
     ausgeführt; gemessen 0,057 ms). 0 Queries auf allen öffentlichen Routen.

## CSRF-Härtung (WP-1, 2026-09-26)

### Origin-Guard (`EnsureSameOrigin`)

`app/Http/Middleware/EnsureSameOrigin.php`, an der **`api`-Gruppe** registriert
(`bootstrap/app.php`), greift **nur** bei `POST|PUT|PATCH|DELETE`:

- `GET`/`HEAD` sind safe-by-Definition und tragen bei same-origin Requests kein
  `Origin` → nie blockiert.
- **Keine Route ist ausgenommen.** Insbesondere die Routen, die eine Session
  *etablieren* (`api/auth/login`, `api/auth/register`), brauchen kein bestehendes
  Cookie — `SameSite=Lax` schützt sie also **nicht**. Eine Ausnahme war dort eine
  reine Schwächung: ein Cross-Site-Browser-Formular trägt immer `Origin` +
  `Sec-Fetch-*` und wird vom generischen Branch ohnehin abgelehnt (vor der
  Entfernung der Liste nachgewiesen: Login mit `Origin: https://evil.example` ⇒
  **200**, Register ⇒ **201** — Signup-CSRF, die Uploads des Opfers landen im
  Angreifer-Account). `api/auth/activate/{token}` ist ein `GET` und für diesen
  Guard ohnehin inert.
- Geprüft wird die **effektive** Methode (`$request->method()` respektiert den
  von Symfony aktivierten `_method`-/`X-HTTP-Method-Override`-Override) — ein
  Cross-Site-Formular mit `POST + _method=PUT` wird also **nicht** umgangen.
- `Origin` fehlt, leer, ist `null` oder gehört nicht zum Origin des Requests →
  **403** mit deutscher Meldung (`„Anfrage von fremder Herkunft abgelehnt."`);
  die Ursache wird geloggt, nicht ausgeliefert.
- Vergleich ist **Origin-strikt**: Schema + Host + Port müssen zum Request
  passen. `http://tenant.example` (Plaintext, MITM-fähig) und ein abweichender
  Port sind **nicht** dieselbe Origin. Einzige Ausnahme ist `local`, und zwar in
  genau **zwei** Formen — der Vite-Dev-Server liefert die SPA auf `:5173` aus und
  proxyt `/api` mit `changeOrigin: true`, der `Host`-Header trägt also das
  Proxy-Target statt des Browser-Origins:
  1. **gleicher Loopback-Host, abweichender Port** — `Origin:
     http://localhost:5173` + `Host: localhost:8000`.
  2. **Loopback-Alias, abweichender Host _und_ Port** — `Origin:
     http://localhost:5173` + `Host: 127.0.0.1:8000`; CI setzt
     `VITE_API_PROXY=http://127.0.0.1:8000`, und beide Namen bezeichnen
     dieselbe Loopback-Schnittstelle. Diese Form fehlte zuvor und blockierte den
     kompletten lokalen Stack mit 403 auf `POST /api/auth/login`.

  Die Alias-Ausnahme ist bewusst so eng wie möglich: sie verlangt `local` **und**
  einen Loopback-`Origin`-Host **und** einen Loopback-Request-Host. Ein
  **Nicht-Loopback**-Origin bleibt damit auch in `local` abgelehnt
  (`Origin: https://evil.example` + `Host: 127.0.0.1:8000` ⇒ **403**) — die
  Regel ist ausdrücklich *kein* „in Dev ist jede Host-Abweichung erlaubt",
  sonst wäre genau das Cross-Site-Loch wieder offen, das der Guard schließt. Die
  Loopback-Allow-List ist fest (`localhost`, `127.0.0.0/8`, IPv6-Loopback in
  jeder Schreibweise inkl. `::ffff:127.0.0.1`) und wird **nicht** per DNS
  aufgelöst (DNS wäre vom Angreifer steuerbar).
- **Dokumentierte Ausnahme bei fehlendem `Origin`:** ein Request **ohne**
  `Origin` **und** **ohne** jeden `Sec-Fetch-*`-Header kann keine Browser-Seite
  einer fremden Site sein (Browser senden `Sec-Fetch-Site` bei jedem Request und
  `Origin` bei jedem Nicht-`GET`/`HEAD`), sondern ein First-Party-API-Client
  (curl, Mobile-App, der Playwright-`APIRequestContext` der E2E-Suite) — der
  kann die Cookie-Jar des Opfers ohnehin nicht benutzen. Wer auch das schließen
  will: `REQUIRE_ORIGIN_HEADER=true`
  (`config/security.php: require_origin_header`).
- **Position in der Pipeline:** die Middleware hängt an der `api`-Gruppe und
  läuft damit **nach** `auth:api` (Laravel sortiert `AuthenticatesRequests` per
  Middleware-Priority nach vorn). Ein unauthentifizierter state-changing
  Request wird daher mit 401 beantwortet, bevor der Guard läuft — harmlos,
  weil ohne Session nichts ausnutzbar ist. Jede Route, die mit einem
  Session-Cookie missbrauchbar wäre, ist `auth:api`-geschützt; für sie läuft der
  Guard immer.
- Wie Laravels eigenes `VerifyCsrfToken` ist der Guard in Console- und
  Unit-Test-Kontext inaktiv.

### Trusted Proxies (WP-1-c)

`bootstrap/app.php` registriert die Trust-Liste in einem `$app->booting()`-
Callback (`TrustProxies::at()` + `withHeaders()`); Auflösung in
`app/Support/TrustedProxyConfig.php`, Quelle
`config/security.php: trusted_proxies` / Env `TRUSTED_PROXIES`
(comma-separierte IPs/CIDRs, `*` = Calling-IP; **Default = Loopback**,
deckt die Compose-Topologie ab).

- **Warum `booting` und nicht `trustProxies()`:** der `withMiddleware()`-
  Callback läuft bei der **Auflösung** des HTTP-/Console-Kernels — also
  **vor** `LoadEnvironmentVariables` und `LoadConfiguration`. Dort war
  `config()` nicht mal gebunden (`bound('config') === false`) und ein
  `Env::get()`-Fallback sah nur die Prozess-Umgebung, nie die `.env`. Ein in
  `.env` gesetzter `TRUSTED_PROXIES`-Wert wurde damit **stillschweigend
  ignoriert** (WF-1-a). `booting` läuft in `Application::boot()`, das der
  `BootProviders`-Bootstrapper erst nach der Config ausführt — `.env` ist
  damit maßgeblich. Gepinnt in `TrustedProxyEnvFileTest`.
- Der Trust-all-Sentinel ist der **String** `'*'`, nicht die Ein-Element-Liste
  `['*']`: die Middleware verzweigt auf `$proxies === '*'`; die Liste
  würde als CIDR an Symfony gehen, wo `'*'` kein gültiger Bereich ist und damit **nichts**
  trustet.

- Ohne Trust ignorierte Symfony **jeden** `X-Forwarded-*`-Header: `$request->
  isSecure()` war **dauerhaft** `false` (absolute URLs in Mails/PKPASS wurden
  zu `http://`), und **jeder** auf `$request->ip()` keyende Rate-Limiter
  (login/register/activate/public/verify/media/admin/resend/apply) kollabierte
  alle Clients in **einen** Bucket — ein Angreifer hätte **alle** Mandanten
  gemeinsam 429-t.
- Vertrauenswürdige Header sind explizit gesetzt: `X-Forwarded-For`,
  `-Host`, `-Port`, `-Proto`. **Nicht** der Framework-Default, der zusätzlich
  `X-Forwarded-Prefix` und das `X-Forwarded-Aws-Elb`-Bundle mittrustet.

### Host-Allow-List (WP-1-d)

`trustHosts()` in `bootstrap/app.php`:

- Dev-Wildcards `^(.+\.)?test$` / `^(.+\.)?localhost$` werden **nur außerhalb**
  von `production` ausgeliefert. Loopback (`localhost`, `127.0.0.1`, `::1`)
  bleibt **immer** in der Allow-List, unabhängig von `TRUSTED_HOSTS` und vom
  Environment: ein Docker-`HEALTHCHECK`/LB-Probe trifft `GET /up` von
  127.0.0.1, und eine Allow-List ohne Loopback antwortet 400 und markiert den
  Container unhealthy (Restart-Loop). Ein Loopback-Host ist kein Tenant und
  erreicht die Mandanten-Logik nie.
- `TRUSTED_HOSTS` (`config/security.php: trusted_hosts`, comma-separierte
  Regexe) **ersetzt nur die Dev-Wildcards** — der Operator benennt damit
  explizit, welche statischen Nicht-Loopback-Hosts erlaubt sind. Die
  Mandanten-Domains werden immer zusätzlich gemergt.
- Der tote Eintrag `localhost:5173` ist entfernt (`getHost()` strippt den Port,
  er konnte nie matchen).
- Die `mandant_domains`-Liste ist **gecacht**
  (`MandantContext::hostnames()`, Key `mandant.hosts_all`, TTL
  `mandants.cache_ttl`) und wird bei Domain-Anlage/Löschung invalidiert
  (`MandantDomainController` → `MandantContext::forgetHostnames()`). Vorher war
  das ein `pluck()` **pro Request**.
- Ein DB-Ausfall ist **laut**: `Log::error` + **500** in `production` (vorher
  stiller Fallback auf eine Allow-List ganz ohne reale Domain ⇒ 400 für alle
  Mandanten). Außerhalb `production` bleiben die Dev-Defaults (Console, Install,
  Erstboot); mit warmem Cache läuft der Betrieb auch im DB-Ausfall weiter.

### `APP_KEY`-Boot-Guard (WP-1-e)

`AppServiceProvider::assertProductionAppKeyIsStrong()` läuft in `boot()`:
`APP_ENV=production` + leerer Key **oder** bekannter Platzhalter (32 Null-Bytes
mit/ohne `base64:`-Präfix, 32 × `A`) ⇒ `Log::critical` + `RuntimeException`,
der Boot bricht ab. `deployment/docker-compose.yml` lieferte bisher
`APP_KEY: ${APP_KEY:-base64:AAAA…}` — ein **funktionierender**, öffentlich
bekannter Schlüssel: alle `Crypt`-Payloads und alle HMAC-signierten Tokens (QR)
wären forgerbar, ohne jede Fehlermeldung. Spiegelt die
`DatabaseSeeder`-Admin-Passwort-Policy.

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
  Das Route-Model-Binding ist seit **M2** auf den **aktuellen Mandanten**
  eingegrenzt (`UserMedia::resolveRouteBindingQuery()`): `user_media` hat keine
  `mandant_id`-Spalte, der einzige gespeicherte Mandanten-Marker ist der
  Storage-Pfad `user-media/{mandantSlug}/…`. Eine fremde Zeile löst darum wie
  eine unbekannte ID zu **404** auf; ohne diese Eingrenzung konnte ein
  Cross-Tenant-Replay aus dem 403/404-Unterschied die Existenz fremder
  Media-Zeilen ableiten (M2).
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
- **B3 (erledigt, WP-1-d nachgezogen):** `trustHosts`-Allow-List aus
  `mandant_domains.hostname` + lokale Defaults (`localhost`, `127.0.0.1`,
  `^\[::1\]$`, und `^(.+\.)?test$` / `^(.+\.)?localhost$` **nur außerhalb
  `production`**) in `bootstrap/app.php`. Fremde Hosts → **400** vor der
  Mandant-Auflösung; allow-listete, aber unbekannte Hosts weiterhin **404**
  (MandantContextMiddleware). Details in „Host-Allow-List (WP-1-d)".
- **B3-Ergänzung (WP-1-d):** Lookup **gecacht** (vorher `pluck()` pro Request),
  Invalidation bei Domain-Anlage/Löschung, env-gatebar via `TRUSTED_HOSTS`, und
  ein DB-Ausfall ist in `production` **laut** (500 + Log) statt still auf eine
  Allow-List ohne reale Domain zu degradieren.
- **CSRF (WP-1-a/b, 2026-09-26):** `SameSite=Lax` in allen Umgebungen
  (Opt-in `JWT_CROSS_SITE_COOKIE=true` für echtes Cross-Site) + Origin-Guard
  auf allen state-changing API-Routen. Details in „CSRF-Härtung".
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
- **#6-1 (erledigt 2026-09-26):** `EnsureMandantMembership` erzwingt die
  Mandanten-Mitgliedschaft **pro Request** für die gesamte `auth:api`-Gruppe
  (Details im Auth-Flow, Schritt 6). Ein JWT ohne Mandanten-Claim, der auf einer
  fremden Mandanten-Domain wiedergespielt wird, erhält jetzt **403** auf jeder
  authentifizierten Route — inklusive der zuvor ungegateten Schreibrouten
  `POST /accreditations/{id}/apply`, `POST /user/media` und
  `PUT /user/profile`; der Media-Upload landet damit nicht mehr im
  Storage-Namespace des fremden Mandanten. Zusatzgewinn: Rollenentzug wirkt
  sofort statt erst nach `JWT_TTL`.
- **#6-1-D1 (erledigt 2026-09-26, Entscheidung des Benutzers):** Ausgenommen sind
  genau `POST /api/auth/logout` und `GET /api/auth/me`
  (`EnsureMandantMembership::EXEMPT_ROUTES`, Route-Name + Methode, **nicht**
  rollenbasiert). Grund: ohne sie bekäme ein gerade entzogenes Konto dort **403**
  statt 204/200 — konkret **200** mit abgelaufenem Cookie auf `/auth/logout`
  (`AuthController::logout()` antwortet mit einem JSON-Body, nicht 204) und
  **200** mit dem eigenen `UserResource` auf `/auth/me` — und könnte den
  httpOnly-Cookie nicht mehr serverseitig loswerden. Beide Routen sind
  Session-Abbau bzw. Lesen des **eigenen** Datensatzes, also keine mandant-
  scoped Mutation: die Cross-Mandant-Lücke bleibt geschlossen, und die
  Schreibrouten bleiben 403. Festgenagelt in
  `MandantMembershipTest::test_a_just_revoked_user_can_still_read_itself_and_log_out`
  (Nutzlast vor dem Entzug 200, danach weiterhin 200, Logout 200 + Cookie
  abgelaufen, Token danach wirklich blacklisted ⇒ 401),
  `…test_the_exemption_does_not_open_the_tenant_scoped_write_routes` (403 auf
  `apply`/`user/media`/`user/profile`/`applications`/`user/media`-Liste) und
  `…test_the_exemption_list_is_exactly_logout_and_me_with_their_methods`.
- **M2 (erledigt 2026-09-27):** 404-vs-403-Existence-Oracle für Non-Members.
  `SubstituteBindings` läuft vor `EnsureMandantMembership`, deshalb entscheidet
  das Route-Model-Binding, ob eine Anfrage überhaupt zum Membership-Check
  gelangt. Alle bereits mandant-gebundenen Modelle (`Accreditation`,
  `Application`, `SubAccreditation`, `Blacklist`, `BadgeImage`, `Event`,
  `Team`, …) tragen ein mandanten-scoped `resolveRouteBindingQuery()`: eine
  **fremde** Zeile löst wie eine unbekannte ID zu **404** auf — kein
  Cross-Tenant-Oracle, Option B ist dort ein No-op. Einzige Ausnahme war
  `UserMedia` (keine `mandant_id`-Spalte) mit globalem `find`: eine in
  **irgendeinem** Mandanten existierende Zeile ergab 403, eine unbekannte 404.
  `UserMedia` bindet jetzt über den Storage-Pfad-Präfix
  `user-media/{mandantSlug}/%` auf den aktuellen Mandanten. Bewusst **keine**
  Änderung der globalen Middleware-Priority: eine Vorverlegung des
  Membership-Checks vor das Binding bräche die per Test festgenagelte Position
  (`MandantMembershipTest::test_the_check_is_positioned_after_auth_and_after_the_rate_limiters`)
  und würde das dokumentierte 403-für-Non-Members-Muster aller Routen auf
  404 verschieben. Festgenagelt in
  `MandantMembershipTest::test_a_foreign_media_row_is_indistinguishable_from_an_unknown_id_for_a_non_member`
  (pre-fix 403 vs. 404) und
  `…test_a_foreign_accreditation_is_indistinguishable_from_an_unknown_id_for_a_non_member`
  (scoped-Modell-Pin). **Rest-Hinweis:** Eine Ressource des **aktuellen**
  Mandanten, die der Angreifer nicht besitzt, bleibt an der Antwort als
  existent (403) von nicht-existent (404) unterscheidbar. Dieses Restrisiko ist
  der bewusst gewählten Reihenfolge „Binding vor Membership" inhärent und wäre
  nur durch deren Umkehr (verboten) zu schließen; offenbart werden weiterhin
  nur sequenzielle Integer-IDs, keine Attribute.
- **F2-Residual (erledigt 2026-09-27):** Der Badge-Export-Guard ist jetzt
  **unconditional**: ein Mandant **ohne eigene Domain** erhält auf
  `POST /api/admin/accreditations/{id}/badges/export` **422** — unabhängig
  davon, ob der `config('app.url')`-Fallback-Host niemandem gehört. Die frühere
  Verengung (422 nur, wenn der Fallback-Host einem **anderen** Mandanten
  gehört) ließ einen domainlosen Mandanten auf einem unowned Host Badges
  erzeugen, die in dem Moment 404-en, in dem dieser Host geroutet wird.
  Festgenagelt in
  `BadgeTest::test_export_refuses_a_domainless_mandant_even_when_the_fallback_host_is_unowned`;
  `BadgeTest::setUp()` gibt `mandantA` jetzt eine echte `mandant_domains`-Zeile
  (`a.test`), damit die übrigen Export-Tests die realistische onboarded-Form
  prüfen. Die Verengung brauchte kein eigenes Prädikat: der frühere
  `MediaHostResolver::ownsFallbackHost()` („gehört der Fallback-Host diesem
  Mandanten?") hatte danach keinen Aufrufer mehr und wurde **entfernt** —
  `MediaHostResolver` stellt nur noch `hostFor()` und `fallbackHost()` bereit,
  Letzteres für `BadgeRenderService`.

Akzeptierte Rest-Risiken (neu bewertet 2026-09-26, WP-1):
- **F6 (info, bleibt akzeptiert):** Die 403-Texte der Auth-Flows
  („Das Konto ist noch nicht aktiviert.", „Dieser Account ist für dieses
  Portal nicht registriert.") offenbaren bewusst die Kontoexistenz. Der
  Origin-Guard (WP-1-b) ändert daran nichts — seine Meldung ist
  absichtlich uniform und verrät keine Ursache.
- **F7 (geschlossen 2026-09-26, Review-Finding #6-1 — war als „Mandant-Check
  nur beim Login" als info-Restrisiko geführt):** Umgesetzt als
  `EnsureMandantMembership` (Middleware, pro Request), siehe „Mitgliedschaft
  pro Request" im Auth-Flow und den Hardening-Eintrag weiter unten. Die
  damalige Einschätzung („der Zugriff wird von der Ressourcen-Ebene entschieden,
  bleibt ein eigenes Arbeitspaket") galt nur für den **Ressourcen-Scope**: das
  per Ressource vorhandene `forMandant()`-Scoping deckte die **Identität** nicht
  ab, und genau die Lücke ist jetzt zentral geschlossen. Bewusst **kein**
  Rest-Risiko mehr, sondern Invariante: „wer authentifiziert ist, muss im
  aktuellen Mandanten eine Rolle haben".
