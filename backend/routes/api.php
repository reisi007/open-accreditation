<?php

use App\Http\Controllers\Api\Admin\MandantController;
use App\Http\Controllers\Api\Admin\MandantDomainController;
use App\Http\Controllers\Api\Admin\MandantMediaController;
use App\Http\Controllers\Api\Admin\TeamController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
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
| Profile & media (auth:api):
|   PUT    /api/user/profile      update own accreditation profile
|   GET    /api/user/media        list own media
|   POST   /api/user/media        upload (portrait|press_id|attachment)
|   GET    /api/user/media/{id}   auth-gated inline delivery (owner-only)
|   DELETE /api/user/media/{id}   delete own media
|
*/

Route::middleware('throttle:5,1')->group(function (): void {
    Route::post('/auth/register', [AuthController::class, 'register'])->name('api.auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('api.auth.login');
});

Route::middleware('throttle:20,1')->get('/auth/activate/{token}', [AuthController::class, 'activate'])->name('api.auth.activate');

Route::middleware('auth:api')->group(function (): void {
    Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');
    Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');

    Route::put('/user/profile', [ProfileController::class, 'update'])->name('api.user.profile.update');

    Route::get('/user/media', [UserMediaController::class, 'index'])->name('api.user.media.index');
    Route::post('/user/media', [UserMediaController::class, 'store'])->name('api.user.media.store');
    Route::get('/user/media/{media}', [UserMediaController::class, 'show'])->name('api.user.media.show');
    Route::delete('/user/media/{media}', [UserMediaController::class, 'destroy'])->name('api.user.media.destroy');
});

/*
|--------------------------------------------------------------------------
| Admin REST API (P2a: Super Admin — Mandanten, Domains, Teams)
|--------------------------------------------------------------------------
|
| All routes sit behind `auth:api` plus a permission gate:
|   - mandants / domains / logo / header → `can:mandants.manage`
|   - teams sub-resource                  → `can:teams.manage`
|
| Both permissions are super_admin-only in this tenant-CRUD surface (the
| team_admin entry in the matrix is scoped to his own team via the gate's
| team argument; TeamController additionally requires the global super admin
| role). Response format: `{data: …}` resources or `{message}` + status;
| deletes return 204. Logo/header delivery is auth-gated like user media.
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

    Route::middleware('can:teams.manage')->group(function (): void {
        Route::get('/mandants/{mandant}/teams', [TeamController::class, 'index'])->name('mandants.teams.index');
        Route::post('/mandants/{mandant}/teams', [TeamController::class, 'store'])->name('mandants.teams.store');
        Route::put('/mandants/{mandant}/teams/{team}', [TeamController::class, 'update'])->name('mandants.teams.update');
        Route::delete('/mandants/{mandant}/teams/{team}', [TeamController::class, 'destroy'])->name('mandants.teams.destroy');
    });
});
