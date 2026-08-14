<?php

use App\Http\Controllers\Api\AccreditationController;
use App\Http\Controllers\Api\Admin\AccreditationController as AdminAccreditationController;
use App\Http\Controllers\Api\Admin\AdminApplicationController;
use App\Http\Controllers\Api\Admin\AdminMediaController;
use App\Http\Controllers\Api\Admin\AdminSubApplicationController;
use App\Http\Controllers\Api\Admin\BlacklistController;
use App\Http\Controllers\Api\Admin\CategoryController;
use App\Http\Controllers\Api\Admin\EventController;
use App\Http\Controllers\Api\Admin\MandantController;
use App\Http\Controllers\Api\Admin\MandantDomainController;
use App\Http\Controllers\Api\Admin\MandantMediaController;
use App\Http\Controllers\Api\Admin\SubAccreditationController as AdminSubAccreditationController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Portal\PortalController;
use App\Http\Controllers\Api\Portal\PortalMediaController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SubAccreditationController;
use App\Http\Controllers\Api\SubApplicationController;
use App\Http\Controllers\Api\UserMediaController;
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
| attempts must not consume the login quota and vice versa.
|
| Profile & media (auth:api):
|   PUT    /api/user/profile      update own accreditation profile
|   GET    /api/user/media        list own media
|   POST   /api/user/media        upload (portrait|press_id|attachment)
|   GET    /api/user/media/{id}   auth-gated inline delivery (owner-only)
|   DELETE /api/user/media/{id}   delete own media
|
*/

Route::middleware('throttle:register')->post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
Route::middleware('throttle:login')->post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');

Route::middleware('throttle:activate')->get('/auth/activate/{token}', [AuthController::class, 'activate'])->name('api.auth.activate');

Route::middleware('auth:api')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');

    Route::put('/user/profile', [ProfileController::class, 'update'])->name('api.user.profile.update');

    Route::get('/user/media', [UserMediaController::class, 'index'])->name('api.user.media.index');
    Route::post('/user/media', [UserMediaController::class, 'store'])->name('api.user.media.store');
    Route::get('/user/media/{media}', [UserMediaController::class, 'show'])->name('api.user.media.show');
    Route::delete('/user/media/{media}', [UserMediaController::class, 'destroy'])->name('api.user.media.destroy');

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
|   - categories                          → `can:categories.manage`
|   - events                              → `can:events.manage`
|   - users / roles                       → `can:users.manage` (P2c)
|
| `mandants.manage`/`teams.manage` are super_admin-only in this tenant-CRUD
| surface. `teams.view` opens the read-only team list for mandant_admin (all
| teams) and team_admin (own teams only — enforced inside the controller).
| `categories.manage`/`events.manage` are also held by mandant_admin
| (whole mandant) and team_admin (own team only — enforced inside the
| controllers via the role assignments). Response format: `{data: …}`
| resources or `{message}` + status; deletes return 204. Logo/header delivery
| is auth-gated like user media.
|
*/
Route::middleware(['auth:api'])->prefix('admin')->name('api.admin.')->group(function (): void {
    Route::middleware('can:mandants.manage')->group(function (): void {
        Route::get('/mandants', [MandantController::class, 'index'])->name('mandants.index');
        Route::post('/mandants', [MandantController::class, 'store'])->name('mandants.store');
        Route::get('/mandants/{mandant}', [MandantController::class, 'show'])->name('mandants.show');
        Route::put('/mandants/{mandant}', [MandantController::class, 'update'])->name('mandants.update');
        Route::delete('/mandants/{mandant}', [MandantController::class, 'destroy'])->name('mandants.destroy');

        Route::get('/mandants/{mandant}/logo', [MandantMediaController::class, 'showLogo'])->name('mandants.logo');
        Route::post('/mandants/{mandant}/logo', [MandantMediaController::class, 'storeLogo'])->name('mandants.logo.store');
        Route::delete('/mandants/{mandant}/logo', [MandantMediaController::class, 'destroyLogo'])->name('mandants.logo.destroy');
        Route::get('/mandants/{mandant}/header', [MandantMediaController::class, 'showHeader'])->name('mandants.header');
        Route::post('/mandants/{mandant}/header', [MandantMediaController::class, 'storeHeader'])->name('mandants.header.store');
        Route::delete('/mandants/{mandant}/header', [MandantMediaController::class, 'destroyHeader'])->name('mandants.header.destroy');

        Route::get('/mandants/{mandant}/domains', [MandantDomainController::class, 'index'])->name('mandants.domains.index');
        Route::post('/mandants/{mandant}/domains', [MandantDomainController::class, 'store'])->name('mandants.domains.store');
        Route::delete('/mandants/{mandant}/domains/{domain}', [MandantDomainController::class, 'destroy'])->name('mandants.domains.destroy');
    });

    Route::get('/mandants/{mandant}/teams', [TeamController::class, 'index'])->middleware('can:teams.view')->name('mandants.teams.index');

    Route::middleware('can:teams.manage')->group(function (): void {
        Route::post('/mandants/{mandant}/teams', [TeamController::class, 'store'])->name('mandants.teams.store');
        Route::put('/mandants/{mandant}/teams/{team}', [TeamController::class, 'update'])->name('mandants.teams.update');
        Route::delete('/mandants/{mandant}/teams/{team}', [TeamController::class, 'destroy'])->name('mandants.teams.destroy');
    });

    Route::middleware('can:categories.manage')->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::put('/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });

    Route::middleware('can:events.manage')->group(function (): void {
        Route::get('/events', [EventController::class, 'index'])->name('events.index');
        Route::post('/events', [EventController::class, 'store'])->name('events.store');
        Route::put('/events/{event}', [EventController::class, 'update'])->name('events.update');
        Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    });

    Route::middleware('can:accreditations.manage')->group(function (): void {
        Route::get('/accreditations', [AdminAccreditationController::class, 'index'])->name('accreditations.index');
        Route::post('/accreditations', [AdminAccreditationController::class, 'store'])->name('accreditations.store');
        Route::put('/accreditations/{accreditation}', [AdminAccreditationController::class, 'update'])->name('accreditations.update');
        Route::delete('/accreditations/{accreditation}', [AdminAccreditationController::class, 'destroy'])->name('accreditations.destroy');
        // P3c: manual allocation trigger (mode=all | mode=first).
        Route::post('/accreditations/{accreditation}/allocate', [AdminAccreditationController::class, 'allocate'])->name('accreditations.allocate');
        // P3d: sub-accreditation (Park-/Sitzkarte) CRUD + manual allocation
        // trigger (mode=all | mode=first, identical contract to P3c).
        Route::get('/accreditations/{accreditation}/sub-accreditations', [AdminSubAccreditationController::class, 'index'])->name('accreditations.sub-accreditations.index');
        Route::post('/accreditations/{accreditation}/sub-accreditations', [AdminSubAccreditationController::class, 'store'])->name('accreditations.sub-accreditations.store');
        Route::put('/sub-accreditations/{sub}', [AdminSubAccreditationController::class, 'update'])->name('sub-accreditations.update');
        Route::delete('/sub-accreditations/{sub}', [AdminSubAccreditationController::class, 'destroy'])->name('sub-accreditations.destroy');
        Route::post('/sub-accreditations/{sub}/allocate', [AdminSubAccreditationController::class, 'allocate'])->name('sub-accreditations.allocate');

        // P3e: admin approval view — blacklist CRUD (mandant-level, only
        // super_admin + mandant_admin), the applications/sub-applications
        // list + single approve/deny/priority actions (via the allocation
        // services) and the admin media list/delivery of an applicant.
        Route::get('/blacklists', [BlacklistController::class, 'index'])->name('blacklists.index');
        Route::post('/blacklists', [BlacklistController::class, 'store'])->name('blacklists.store');
        Route::delete('/blacklists/{blacklist}', [BlacklistController::class, 'destroy'])->name('blacklists.destroy');

        Route::get('/applications', [AdminApplicationController::class, 'index'])->name('applications.index');
        Route::put('/applications/{application}', [AdminApplicationController::class, 'update'])->name('applications.update');
        Route::get('/applications/{application}/media', [AdminMediaController::class, 'index'])->name('applications.media');

        Route::get('/sub-applications', [AdminSubApplicationController::class, 'index'])->name('sub-applications.index');
        Route::put('/sub-applications/{subApplication}', [AdminSubApplicationController::class, 'update'])->name('sub-applications.update');

        Route::get('/user-media/{media}', [AdminMediaController::class, 'show'])->name('user-media.show');
    });

    Route::middleware('can:users.manage')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles.update');
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
