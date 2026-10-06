<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccreditationController;
use App\Http\Controllers\Api\Admin\AccreditationController as AdminAccreditationController;
use App\Http\Controllers\Api\Admin\AdminApplicationController;
use App\Http\Controllers\Api\Admin\AdminMediaController;
use App\Http\Controllers\Api\Admin\AdminSubApplicationController;
use App\Http\Controllers\Api\Admin\BadgeAssetController;
use App\Http\Controllers\Api\Admin\BadgeExportController;
use App\Http\Controllers\Api\Admin\BadgeImageController;
use App\Http\Controllers\Api\Admin\BadgeTemplateController;
use App\Http\Controllers\Api\Admin\BlacklistController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Api\Admin\EventParticipantController;
use App\Http\Controllers\Api\Admin\EventTypeController;
use App\Http\Controllers\Api\Admin\FailedMailController;
use App\Http\Controllers\Api\Admin\MandantController;
use App\Http\Controllers\Api\Admin\MandantDomainController;
use App\Http\Controllers\Api\Admin\MandantMediaController;
use App\Http\Controllers\Api\Admin\SubAccreditationController as AdminSubAccreditationController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Admin\VenueController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\MandantMediaSelfServiceController;
use App\Http\Controllers\Api\Portal\PortalController;
use App\Http\Controllers\Api\Portal\PortalMediaController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SubAccreditationController;
use App\Http\Controllers\Api\SubApplicationController;
use App\Http\Controllers\Api\UserMediaController;
use App\Http\Controllers\Api\VerifyController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (P1b/P1c: Auth, roles, profile, media)
|--------------------------------------------------------------------------
|
| Auth flow:
|   POST   /api/auth/register     create user in current mandant + activation mail
|   GET    /api/auth/activate/{t} consume one-time activation token
|   POST   /api/auth/login        JWT into httpOnly cookie (accr_jwt)
|   POST   /api/auth/logout       invalidate JWT + clear cookie
|   GET    /api/auth/me           current user (UserResource)
|
| Login and register use their own named throttle buckets (`throttle:login` /
| `throttle:register`, registered in AppServiceProvider) — B2: register
| attempts must not consume the login quota and vice versa. `/auth/logout` and
| `/auth/me` — the two `EnsureMandantMembership` exemptions, and the only two
| routes that log per request for an account without a role in the current
| mandant — carry an inline per-user bucket (M3, see the comment on the
| routes).
|
| Profile & account (auth:api):
|   PUT    /api/user/profile      update own accreditation profile
|   GET    /api/user/account      own identity + the counts a deletion
|                                 confirmation dialog has to name
|   DELETE /api/user/account      hard-delete the OWN account (DSGVO, no
|                                 anonymisation) — no gate: the target is
|                                 `$request->user()`, so `auth:api` is the
|                                 whole authorisation
|   GET    /api/user/media        list own media
|   POST   /api/user/media        upload (portrait|press_id|attachment)
|   GET    /api/user/media/{id}   auth-gated inline delivery (owner-only)
|   DELETE /api/user/media/{id}   delete own media
|
| Mandant self-service media (auth:api + `can:mandant.media.manage`, P8b):
|   GET    /api/mandant/logo      own mandant's logo delivery (inline)
|   POST   /api/mandant/logo      upload/replace own mandant's logo
|   DELETE /api/mandant/logo      delete own mandant's logo
|   GET    /api/mandant/header    own mandant's header delivery (inline)
|   POST   /api/mandant/header    upload/replace own mandant's header
|   DELETE /api/mandant/header    delete own mandant's header
| The target mandant is derived from MandantContext (never a request
| parameter) — mandant_admin manages only his own mandant (no IDOR); the gate
| denies him once the current context is a foreign mandant. super_admin keeps
| the full admin surface (`api.admin.mandants.*`).
|
*/

Route::middleware('throttle:register')->post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::middleware('throttle:login')->post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

Route::middleware('throttle:activate')->get('/auth/activate/{token}', [AuthController::class, 'activate'])->name('api.auth.activate');

Route::middleware('auth:api')->group(function (): void {
    // M3: the two `EnsureMandantMembership` EXEMPT_ROUTES carry a rate limit
    // because they are the only two routes that answer for an account WITHOUT
    // a role in the current mandant — and both write a log line per request
    // (`logExempt()`, `deny()`). A foreign-token replay loop against `/auth/me`
    // would therefore be an unbounded log-write amplifier. The inline
    // `60,1` bucket is keyed per authenticated user (Laravel's
    // `ThrottleRequests::resolveRequestSignature()`), so one misbehaving
    // account can never exhaust anybody else's budget; 60/min is far above the
    // SPA's actual rate (one `/auth/me` per page load).
    //
    // The third parameter is the limiter PREFIX: without it both routes would
    // share ONE key (the user id is the whole signature), so a page reload
    // loop on `/auth/me` would lock the user out of `/auth/logout` — the one
    // call that has to work to clear his session.
    Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('throttle:60,1,auth-logout')->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->middleware('throttle:60,1,auth-me')->name('api.auth.me');

    // Self-service account surface. No `can:` gate HERE on purpose: the target
    // is `$request->user()` — the account the JWT was minted for — so a gate
    // would evaluate a permission of the account against itself and could only
    // ever add a second source of "you may not do that". `auth:api` is the
    // whole authorisation, and `GET /api/user/account` exists so the
    // confirmation dialog can name the account AND its application count
    // before anything is deleted (a count read afterwards is always zero).
    Route::get('/user/account', [AccountController::class, 'show'])->name('api.user.account.show');
    Route::delete('/user/account', [AccountController::class, 'destroy'])->name('api.user.account.destroy');

    Route::put('/user/profile', [ProfileController::class, 'update'])->name('api.user.profile.update');

    Route::get('/user/media', [UserMediaController::class, 'index'])->name('api.user.media.index');
    Route::post('/user/media', [UserMediaController::class, 'store'])->middleware('throttle:media')->name('api.user.media.store');
    Route::get('/user/media/{media}', [UserMediaController::class, 'show'])->name('api.user.media.show');
    Route::delete('/user/media/{media}', [UserMediaController::class, 'destroy'])->name('api.user.media.destroy');

    // P8b: mandant self-service logo/header (mandant_admin manages the OWN
    // mandant's media). The mandant is resolved from MandantContext inside the
    // controller — the gate denies once the current context is foreign.
    // Uploads (F5) share the per-user `media` upload limiter.
    Route::prefix('mandant')->middleware('can:mandant.media.manage')->name('api.mandant.')->group(function (): void {
        Route::get('/logo', [MandantMediaSelfServiceController::class, 'showLogo'])->name('logo');
        Route::post('/logo', [MandantMediaSelfServiceController::class, 'storeLogo'])->middleware('throttle:media')->name('logo.store');
        Route::delete('/logo', [MandantMediaSelfServiceController::class, 'destroyLogo'])->name('logo.destroy');
        Route::get('/header', [MandantMediaSelfServiceController::class, 'showHeader'])->name('header');
        Route::post('/header', [MandantMediaSelfServiceController::class, 'storeHeader'])->middleware('throttle:media')->name('header.store');
        Route::delete('/header', [MandantMediaSelfServiceController::class, 'destroyHeader'])->name('header.destroy');
    });

    // P3b: apply for an accreditation (deadline/duplicate guarded in the
    // controller) and "Meine Akkreditierungen". Apply is throttled per
    // authenticated user (fallback per-ip) via the named `apply` limiter.
    Route::post('/accreditations/{accreditation}/apply', [AccreditationController::class, 'apply'])->middleware('throttle:apply')->name('api.accreditations.apply');
    Route::get('/applications', [ApplicationController::class, 'index'])->name('api.applications.index');
    Route::delete('/applications/{application}', [ApplicationController::class, 'destroy'])->name('api.applications.destroy');

    // P3d: sub-accreditation (Park-/Sitzkarte) apply + "Meine
    // Sub-Akkreditierungen". Apply reuses the same per-user `apply` limiter
    // as the main apply (auth-gated, so the shared bucket only ever holds
    // authenticated users; the unique (sub_accreditation_id, application_id)
    // constraint already blocks scripted duplicates).
    Route::post('/sub-accreditations/{sub}/apply', [SubAccreditationController::class, 'apply'])->middleware('throttle:apply')->name('api.sub-accreditations.apply');
    Route::get('/sub-applications', [SubApplicationController::class, 'index'])->name('api.sub-applications.index');
    Route::delete('/sub-applications/{subApplication}', [SubApplicationController::class, 'destroy'])->name('api.sub-applications.destroy');

    // P6: wallet passes. Apple .pkpass download (and the Google payload) of
    // an own approved application / sub-application — ownership + mandant
    // scope + `approved` status are enforced in WalletController (foreign
    // 404, not approved 422).
    Route::get('/applications/{application}/wallet', [WalletController::class, 'apple'])->name('api.applications.wallet');
    Route::get('/applications/{application}/wallet/google', [WalletController::class, 'google'])->name('api.applications.wallet.google');
    Route::get('/sub-applications/{subApplication}/wallet', [WalletController::class, 'subApple'])->name('api.sub-applications.wallet');
});

/*
|--------------------------------------------------------------------------
| Admin REST API (P2a/P2b/P2c: Super Admin — Mandanten, Domains, Teams,
| Kategorien, Events, Benutzer)
|--------------------------------------------------------------------------
|
| All routes sit behind `auth:api` plus a permission gate:
|   - mandants / domains / logo / header → `can:mandants.manage`
|   - teams read (index)                  → `can:teams.view` (P2b-F1)
|   - teams write                         → `can:teams.manage` (super_admin-only)
|   - team logo write                     → `can:teams.media.manage` (W4-F1,
|     hierarchical: mandant_admin whole mandant, team_admin own team)
|   - categories                          → `can:categories.manage`
|   - venues                              → `can:venues.manage` (W12)
|   - events                              → `can:events.manage`
|   - users / roles                       → `can:users.manage` (P2c; since F4
|     also `team_admin`, TEAM-SCOPED in the controller)
|   - user account deletion               → `can:users.delete` (DSGVO). NOT
|     `users.manage`: that one is role assignment, and whoever may hand out
|     roles may not thereby end accounts. Same mandant-scoped `{user}` binding.
|
| `mandants.manage`/`teams.manage` are super_admin-only in this tenant-CRUD
| surface. `teams.view` opens the read-only team list for mandant_admin (all
| teams) and team_admin (own teams only — enforced inside the controller).
| `categories.manage`/`events.manage` are also held by mandant_admin
| (whole mandant) and team_admin (own team only — enforced inside the
| controllers via the role assignments). `venues.manage` (W12) is held by
| mandant_admin and team_admin, exactly like `categories.manage`: a venue is
| mandant-wide reference data (never team-owned), so the surface is
| mandant-scoped — but the team_admin grant is required, not a privilege: the
| venue combobox in the team AND the event form reads `GET /api/admin/venues`
| and creates inline, and team_admin may edit both forms (`teams.manage`,
| `events.manage`). Without the grant that picker 403s and the create
| affordance dead-ends. Response format: `{data: …}` resources or `{message}` +
| status; deletes return 204 (a referenced venue answers 409 with the
| reference counts). Logo/header delivery is auth-gated like user media.
|
| P2a-RL: every mutating admin route (POST/PUT/DELETE) carries
| `throttle:admin` (named limiter, 300/min per authenticated admin user, key
| `admin:{userId|ip}` — registered in AppServiceProvider). The admin
| GET/read routes are deliberately NOT throttled: lists are auth-gated
| already and a shared bucket would harm legit admin browsing. BOTH resend
| routes additionally carry `throttle:resend` (10/min, P5-F2) — the pass resend
| (`applications.resend`) and its Park-/Sitzkarte counterpart
| (`sub-applications.resend`) — which is far stricter than the shared admin
| write budget.
|
*/
Route::middleware(['auth:api'])->prefix('admin')->name('api.admin.')->group(function (): void {
    Route::middleware('can:mandants.manage')->group(function (): void {
        Route::get('/mandants', [MandantController::class, 'index'])->name('mandants.index');
        Route::post('/mandants', [MandantController::class, 'store'])->middleware('throttle:admin')->name('mandants.store');
        Route::get('/mandants/{mandant}', [MandantController::class, 'show'])->name('mandants.show');
        Route::put('/mandants/{mandant}', [MandantController::class, 'update'])->middleware('throttle:admin')->name('mandants.update');
        Route::delete('/mandants/{mandant}', [MandantController::class, 'destroy'])->middleware('throttle:admin')->name('mandants.destroy');

        Route::get('/mandants/{mandant}/logo', [MandantMediaController::class, 'showLogo'])->name('mandants.logo');
        Route::post('/mandants/{mandant}/logo', [MandantMediaController::class, 'storeLogo'])->middleware('throttle:admin')->name('mandants.logo.store');
        Route::delete('/mandants/{mandant}/logo', [MandantMediaController::class, 'destroyLogo'])->middleware('throttle:admin')->name('mandants.logo.destroy');
        Route::get('/mandants/{mandant}/header', [MandantMediaController::class, 'showHeader'])->name('mandants.header');
        Route::post('/mandants/{mandant}/header', [MandantMediaController::class, 'storeHeader'])->middleware('throttle:admin')->name('mandants.header.store');
        Route::delete('/mandants/{mandant}/header', [MandantMediaController::class, 'destroyHeader'])->middleware('throttle:admin')->name('mandants.header.destroy');

        Route::get('/mandants/{mandant}/domains', [MandantDomainController::class, 'index'])->name('mandants.domains.index');
        Route::post('/mandants/{mandant}/domains', [MandantDomainController::class, 'store'])->middleware('throttle:admin')->name('mandants.domains.store');
        Route::delete('/mandants/{mandant}/domains/{domain}', [MandantDomainController::class, 'destroy'])->middleware('throttle:admin')->name('mandants.domains.destroy');
    });

    Route::get('/mandants/{mandant}/teams', [TeamController::class, 'index'])->middleware('can:teams.view')->name('mandants.teams.index');

    Route::middleware('can:teams.manage')->group(function (): void {
        Route::post('/mandants/{mandant}/teams', [TeamController::class, 'store'])->middleware('throttle:admin')->name('mandants.teams.store');
        Route::put('/mandants/{mandant}/teams/{team}', [TeamController::class, 'update'])->middleware('throttle:admin')->name('mandants.teams.update');
        Route::delete('/mandants/{mandant}/teams/{team}', [TeamController::class, 'destroy'])->middleware('throttle:admin')->name('mandants.teams.destroy');
    });

    // W4: team logo (Vereins-Logo). Read follows `teams.view` (the delivery is
    // auth-gated inline); writes follow `teams.media.manage` (W4-F1) —
    // mandant_admin manages every team of his mandant, team_admin only his own
    // team(s), re-enforced inside the controller via the role assignments.
    // Files live on the public `media` disk under the W1 layout
    // (`<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`).
    Route::get('/teams/{team}/logo', [TeamController::class, 'showLogo'])->middleware('can:teams.view')->name('teams.logo');
    Route::post('/teams/{team}/logo', [TeamController::class, 'storeLogo'])->middleware(['can:teams.media.manage', 'throttle:admin'])->name('teams.logo.store');
    Route::delete('/teams/{team}/logo', [TeamController::class, 'destroyLogo'])->middleware(['can:teams.media.manage', 'throttle:admin'])->name('teams.logo.destroy');

    Route::middleware('can:categories.manage')->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->middleware('throttle:admin')->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->middleware('throttle:admin')->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('throttle:admin')->name('categories.destroy');
    });

    // W12: venues (Spielstätten) — mandant-wide master data referenced by BOTH
    // teams (`venue_id`, the Verein's default location) and events
    // (`venue_id`, an event that deviates from it). The rows are mandant-scoped
    // (no team level exists), so the gate mirrors the categories surface and is
    // held by super_admin, mandant_admin AND team_admin: the combobox in the
    // team and the event form reads this index and creates inline, and both of
    // those forms are already editable by a team_admin (`teams.manage` /
    // `events.manage`) — denying the gate would 403 the picker and the inline
    // create he is supposed to have there. A foreign venue id is a 404
    // (mandant-scoped route binding + `assertMandantScope`).
    // A referenced venue is DEACTIVATED, not deleted; DELETE answers 409 with
    // the reference counts when a team or an event still points at the row.
    Route::middleware('can:venues.manage')->group(function (): void {
        Route::get('/venues', [VenueController::class, 'index'])->name('venues.index');
        Route::post('/venues', [VenueController::class, 'store'])->middleware('throttle:admin')->name('venues.store');
        Route::put('/venues/{venue}', [VenueController::class, 'update'])->middleware('throttle:admin')->name('venues.update');
        Route::delete('/venues/{venue}', [VenueController::class, 'destroy'])->middleware('throttle:admin')->name('venues.destroy');
    });

    // W12 (board 7b): the same surface ADDRESSED BY MANDANT, for the one admin
    // page that addresses a mandant by URL (`/admin/mandants/{id}`) — its team
    // form carries the venue combobox. The host-scoped routes above resolve
    // their mandant from the request HOST, so on that page the picker offered
    // the host mandant's venues and its inline create WROTE into the host
    // mandant without an error (only the team save afterwards 404'd). The gate
    // is identical (`venues.manage`); the difference is the scope, enforced in
    // the controller via `assertMandantRouteParameter()`: super_admin may
    // address ANY mandant from any host, everyone else only the mandant he is
    // already on (else 404). So this grants no one new reach — it only makes
    // the page read and write the mandant it is actually about. The host-scoped
    // routes stay for the host-relative pages (categories, events,
    // accreditations, VenuesPage).
    Route::middleware('can:venues.manage')->group(function (): void {
        Route::get('/mandants/{mandant}/venues', [VenueController::class, 'indexForMandant'])->name('mandants.venues.index');
        Route::post('/mandants/{mandant}/venues', [VenueController::class, 'storeForMandant'])->middleware('throttle:admin')->name('mandants.venues.store');
        Route::put('/mandants/{mandant}/venues/{venue}', [VenueController::class, 'updateForMandant'])->middleware('throttle:admin')->name('mandants.venues.update');
        Route::delete('/mandants/{mandant}/venues/{venue}', [VenueController::class, 'destroyForMandant'])->middleware('throttle:admin')->name('mandants.venues.destroy');
    });

    Route::middleware('can:events.manage')->group(function (): void {
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/events', [EventController::class, 'store'])->middleware('throttle:admin')->name('events.store');
        Route::put('/events/{event}', [EventController::class, 'update'])->middleware('throttle:admin')->name('events.update');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->middleware('throttle:admin')->name('events.destroy');

        // W2: mandant event types (slug unique per mandant, optional public
        // logo below the W1 media layout, structural `presets` envelope).
        // Writes are super_admin/mandant_admin only (team_admin 403 inside the
        // controller); logo delivery is auth-gated like the other media.
        Route::get('/event-types', [EventTypeController::class, 'index'])->name('event-types.index');
        Route::post('/event-types', [EventTypeController::class, 'store'])->middleware('throttle:admin')->name('event-types.store');
        Route::put('/event-types/{eventType}', [EventTypeController::class, 'update'])->middleware('throttle:admin')->name('event-types.update');
        Route::delete('/event-types/{eventType}', [EventTypeController::class, 'destroy'])->middleware('throttle:admin')->name('event-types.destroy');

        Route::get('/event-types/{eventType}/logo', [EventTypeController::class, 'showLogo'])->name('event-types.logo');
        Route::post('/event-types/{eventType}/logo', [EventTypeController::class, 'storeLogo'])->middleware('throttle:admin')->name('event-types.logo.store');
        Route::delete('/event-types/{eventType}/logo', [EventTypeController::class, 'destroyLogo'])->middleware('throttle:admin')->name('event-types.logo.destroy');

        // W4: cardinality-free event participants (Single / Versus / N).
        // Writes are super_admin/mandant_admin only (team_admin 403 inside the
        // controller); reads stay open to team_admin.
        Route::get('/events/{event}/participants', [EventParticipantController::class, 'index'])->name('events.participants.index');
        Route::post('/events/{event}/participants', [EventParticipantController::class, 'store'])->middleware('throttle:admin')->name('events.participants.store');
        Route::put('/events/{event}/participants/{participant}', [EventParticipantController::class, 'update'])->middleware('throttle:admin')->name('events.participants.update');
        Route::delete('/events/{event}/participants/{participant}', [EventParticipantController::class, 'destroy'])->middleware('throttle:admin')->name('events.participants.destroy');
    });

    Route::middleware('can:accreditations.manage')->group(function (): void {
        Route::get('/accreditations', [AdminAccreditationController::class, 'index'])->name('accreditations.index');
        Route::post('/accreditations', [AdminAccreditationController::class, 'store'])->middleware('throttle:admin')->name('accreditations.store');
        Route::put('/accreditations/{accreditation}', [AdminAccreditationController::class, 'update'])->middleware('throttle:admin')->name('accreditations.update');
        Route::delete('/accreditations/{accreditation}', [AdminAccreditationController::class, 'destroy'])->middleware('throttle:admin')->name('accreditations.destroy');
        // P3c: manual allocation trigger (mode=all | mode=first).
        Route::post('/accreditations/{accreditation}/allocate', [AdminAccreditationController::class, 'allocate'])->middleware('throttle:admin')->name('accreditations.allocate');
        // P3d: sub-accreditation (Park-/Sitzkarte) CRUD + manual allocation
        // trigger (mode=all | mode=first, identical contract to P3c).
        Route::get('/accreditations/{accreditation}/sub-accreditations', [AdminSubAccreditationController::class, 'index'])->name('accreditations.sub-accreditations.index');
        Route::post('/accreditations/{accreditation}/sub-accreditations', [AdminSubAccreditationController::class, 'store'])->middleware('throttle:admin')->name('accreditations.sub-accreditations.store');
        // P3e-B4: mandant-wide sub-accreditation filter endpoint — one
        // request with server-side filters instead of N parallel
        // per-accreditation requests from the approval view.
        Route::get('/sub-accreditations', [AdminSubAccreditationController::class, 'indexAll'])->name('sub-accreditations.index');
        Route::put('/sub-accreditations/{sub}', [AdminSubAccreditationController::class, 'update'])->middleware('throttle:admin')->name('sub-accreditations.update');
        Route::delete('/sub-accreditations/{sub}', [AdminSubAccreditationController::class, 'destroy'])->middleware('throttle:admin')->name('sub-accreditations.destroy');
        Route::post('/sub-accreditations/{sub}/allocate', [AdminSubAccreditationController::class, 'allocate'])->middleware('throttle:admin')->name('sub-accreditations.allocate');

        // P3e: admin approval view — blacklist CRUD (mandant-level, only
        // super_admin + mandant_admin), the applications/sub-applications
        // list + single approve/deny/priority actions (via the allocation
        // services) and the admin media list/delivery of an applicant.
        Route::get('/blacklists', [BlacklistController::class, 'index'])->name('blacklists.index');
        Route::post('/blacklists', [BlacklistController::class, 'store'])->middleware('throttle:admin')->name('blacklists.store');
        Route::delete('/blacklists/{blacklist}', [BlacklistController::class, 'destroy'])->middleware('throttle:admin')->name('blacklists.destroy');

        Route::get('/applications', [AdminApplicationController::class, 'index'])->name('applications.index');
        Route::put('/applications/{application}', [AdminApplicationController::class, 'update'])->middleware('throttle:admin')->name('applications.update');
        Route::post('/applications/{application}/resend', [AdminApplicationController::class, 'resend'])->middleware('throttle:resend')->name('applications.resend');
        Route::get('/applications/{application}/media', [AdminMediaController::class, 'index'])->name('applications.media');

        Route::get('/sub-applications', [AdminSubApplicationController::class, 'index'])->name('sub-applications.index');
        Route::put('/sub-applications/{subApplication}', [AdminSubApplicationController::class, 'update'])->middleware('throttle:admin')->name('sub-applications.update');
        // P6 follow-up: the sub counterpart of the pass-resend route above —
        // same gates, same 422 on a non-mailable status, same `throttle:resend`
        // limiter (a mail trigger must not share the 300/min admin write budget).
        Route::post('/sub-applications/{subApplication}/resend', [AdminSubApplicationController::class, 'resend'])->middleware('throttle:resend')->name('sub-applications.resend');

        Route::get('/user-media/{media}', [AdminMediaController::class, 'show'])->name('user-media.show');

        // P4: badge templates (CRUD — team_admin read-only, writes are 403 in
        // the controller) and the badge export (PDF/CSV stream). Both live on
        // the accreditations.manage surface.
        Route::get('/badge-templates', [BadgeTemplateController::class, 'index'])->name('badge-templates.index');
        Route::post('/badge-templates', [BadgeTemplateController::class, 'store'])->middleware('throttle:admin')->name('badge-templates.store');
        Route::put('/badge-templates/{badgeTemplate}', [BadgeTemplateController::class, 'update'])->middleware('throttle:admin')->name('badge-templates.update');
        Route::delete('/badge-templates/{badgeTemplate}', [BadgeTemplateController::class, 'destroy'])->middleware('throttle:admin')->name('badge-templates.destroy');

        // P4: mandant-owned badge images for freely placed `image` layout
        // entries — upload/delivery surface. The delivery route is read-only
        // (no throttle:admin) so editor thumbnails load unthrottled.
        Route::get('/badge-images', [BadgeImageController::class, 'index'])->name('badge-images.index');
        Route::post('/badge-images', [BadgeImageController::class, 'store'])->middleware('throttle:admin')->name('badge-images.store');
        Route::get('/badge-images/{badgeImage}/file', [BadgeImageController::class, 'showFile'])->name('badge-images.show-file');
        Route::delete('/badge-images/{badgeImage}', [BadgeImageController::class, 'destroy'])->middleware('throttle:admin')->name('badge-images.destroy');

        // The bundled badge assets (no mandant-owned data, but the same
        // auth-gated surface as the badge templates): today only the person
        // silhouette that stands in for a missing portrait in a `photo` entry.
        // Read-only like the delivery route above, so the editor canvas loads it
        // unthrottled.
        Route::get('/badge-assets/photo-placeholder', [BadgeAssetController::class, 'photoPlaceholder'])->name('badge-assets.photo-placeholder');

        Route::post('/accreditations/{accreditation}/badges/export', [BadgeExportController::class, 'export'])->middleware('throttle:admin')->name('accreditations.badges.export');
    });

    // Role assignment. `mandant_admin` gets the whole mandant, `team_admin`
    // (F4, Nutzerentscheid 2026-10-06) only his own teams — the gate cannot
    // express that, because it passes no `team_id` and `hasPermission()` reads
    // a missing argument as "my own team" for a team_admin, so the narrowing
    // is re-enforced in `UserController` (class docblock, and the `F4` block in
    // `config/permissions.php`). The holder set is what separates the two
    // routes below: measured on the shipped matrix, a `team_admin` passes this
    // gate and is 403 at the delete gate.
    Route::middleware('can:users.manage')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->middleware('throttle:admin')->name('users.roles.update');
    });

    // Account termination sits behind its OWN gate, `users.delete`, and NOT
    // inside the `users.manage` group above: that one is ROLE ASSIGNMENT, and
    // hanging account termination off it would give whoever may hand out roles
    // the power to end an account. Same mandant-scoped `{user}` binding, so a
    // foreign target is a 404. `mandant_admin` + `super_admin` only — this is
    // the gate that stays un-swappable now that `team_admin` holds
    // `users.manage` (F4).
    Route::middleware('can:users.delete')->group(function (): void {
        Route::delete('/users/{user}', [UserController::class, 'destroy'])->middleware('throttle:admin')->name('users.destroy');
    });

    // Position 45 (2026-10-02): the dead-letter queue for undelivered mandant
    // mails. Its OWN permission (`mails.dlq.manage`), deliberately NOT
    // `accreditations.manage`: a team_admin holds the latter but must never read
    // a Verband-wide delivery error list (it carries recipient addresses).
    // Mandant isolation is enforced in the controller: `mandant_admin` only his
    // own mandant, `super_admin` all. No read throttle (mirrors the other admin
    // list routes); requeue is a write and carries `throttle:admin`.
    Route::middleware('can:mails.dlq.manage')->group(function (): void {
        Route::get('/failed-mails', [FailedMailController::class, 'index'])->name('failed-mails.index');
        Route::post('/failed-mails/{id}/requeue', [FailedMailController::class, 'requeue'])->middleware('throttle:admin')->name('failed-mails.requeue');
    });
});

/*
|--------------------------------------------------------------------------
| Public portal API (P3a: Mandant-Übersicht, Event-Kalender, Event-Detail)
|--------------------------------------------------------------------------
|
| Auth-free by design — the portal is the public landing surface (the D12
| public verification page arrives in P4). Every route is scoped to the
| current mandant from MandantContext; an unknown/absent mandant is a 404
| (MandantContextMiddleware in production). Read-only, hence only a light
| `throttle:public` keeps scraping in check. Responses: `{data: …}` (media
| delivery streams the file; 404 `{message}` without an image).
|
|   GET /api/portal/overview        mandant + teams (teams only when
|                                   `teams_enabled` and mandant active)
|   GET /api/portal/events          active events, date ASC; filters
|                                   `team_id` (foreign → 422), `competition`
|   GET /api/portal/events/{event}  active event detail (+ venue_effective,
|                                   deadline_effective, contact)
|   GET /api/portal/mandant/logo    public logo delivery (inline)
|   GET /api/portal/mandant/header  public header delivery (inline)
|
*/
Route::prefix('portal')->middleware('throttle:public')->name('api.portal.')->group(function (): void {
    Route::get('/overview', [PortalController::class, 'overview'])->name('overview');
    Route::get('/events', [PortalController::class, 'events'])->name('events');
    Route::get('/events/{event}', [PortalController::class, 'show'])->name('events.show');
    Route::get('/mandant/logo', [PortalMediaController::class, 'logo'])->name('mandant.logo');
    Route::get('/mandant/header', [PortalMediaController::class, 'header'])->name('mandant.header');
});

/*
|--------------------------------------------------------------------------
| Public accreditation API (P3b: Akkreditierungen)
|--------------------------------------------------------------------------
|
| Auth-free like the portal — the accreditation list/detail is the public
| application surface. Scoped to the current mandant from MandantContext.
| `GET /api/accreditations` (optional `event_id` filter, foreign → 422) and
| `GET /api/accreditations/{id}` (inactive/foreign → 404). A light
| `throttle:public` keeps scraping in check. Responses: `{data: …}`.
|
*/
Route::prefix('accreditations')->middleware('throttle:public')->name('api.accreditations.')->group(function (): void {
    Route::get('/', [AccreditationController::class, 'index'])->name('index');
    Route::get('/{accreditation}', [AccreditationController::class, 'show'])->name('show');
    // P3d: public sub-accreditation list (Park-/Sitzkarten) of one active
    // main accreditation. Inactive/foreign main → 404 (same semantics as the
    // accreditation detail route).
    Route::get('/{accreditation}/sub-accreditations', [SubAccreditationController::class, 'index'])->name('sub-accreditations.index');
});

/*
|--------------------------------------------------------------------------
| Public QR verification (P4: D12 — Ausweis-Verifikation)
|--------------------------------------------------------------------------
|
| Auth-free like the portal; the signed `{token}` is the access credential.
| `GET /api/verify/{token}` answers `{data: {status, …}}` (identity only for
| `approved` applications) and `GET /api/verify/{token}/photo` streams the
| approved applicant's portrait inline. A dedicated `throttle:verify` keeps
| scanning in check — its own per-ip bucket, decoupled from the portal and
| accreditation-read traffic (P4-F3).
|
*/
Route::prefix('verify')->middleware('throttle:verify')->name('api.verify.')->group(function (): void {
    Route::get('/{token}/photo', [VerifyController::class, 'photo'])->name('photo');
    Route::get('/{token}', [VerifyController::class, 'verify'])->name('show');
});
