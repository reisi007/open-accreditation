<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\MandantContext;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-request mandant membership for every authenticated API request.
 *
 * **The hole this closes (#6).** `User::getJWTCustomClaims()` is empty — the
 * JWT carries NO mandant claim. A token minted on `a.example` is therefore
 * cryptographically valid on `b.example` too, and the only mandant check in
 * the auth flow (`AuthController::mayLogInOnCurrentMandant()`) runs ONCE, at
 * login. Replaying the cookie with a foreign `Host` header got past
 * `auth:api` and reached the un-gated write routes of the `auth:api` group:
 * `POST /accreditations/{accreditation}/apply` created an application INSIDE
 * the foreign mandant (the resource was `forMandant()`-scoped, the IDENTITY
 * was not), `POST /user/media` wrote the upload into the foreign mandant's
 * storage namespace (`MandantContext::current()?->slug`), and the foreign
 * mandant's admins then saw that applicant — portrait and press id included —
 * in their approval queue.
 *
 * **Why per request and not a JWT claim.** A single `mid` claim would have to
 * be re-issued on every role change AND on every mandant switch, and it
 * breaks the multi-mandant user (roles in several mandants ⇒ one claim cannot
 * describe them). Re-reading the pivot per request costs exactly ONE indexed
 * `EXISTS` (see `User::isMemberOfMandant()`) and buys immediate revocation: a
 * role removed in mandant A stops working on the next request instead of after
 * `JWT_TTL`. A claim-based optimisation is deliberately out of scope.
 *
 * **It supplements, never replaces, the resource scoping.** The per-resource
 * `forMandant()` scoping stays exactly as it is — this is the outer identity
 * check ("may this account act in this mandant at all?"), the controllers
 * keep answering "is THIS resource mine?".
 *
 * **Scope: authenticated API requests only.** Inert for
 *  - every route without `auth:api` (public portal, public accreditation
 *    list, QR `verify`, login/register/activate): the `api` guard never
 *    resolved a user there and `hasUser()` does not resolve one, so the
 *    middleware performs NO query and cannot change the answer,
 *  - console/CLI and requests without a resolved mandant (unknown host in
 *    testing, no host at all): no tenant, nothing to be a member of — the
 *    `MandantContext::hasCurrent()` guard the rest of the app uses.
 *
 * `hasUser()` is deliberately NOT `$request->user('api')` / `guard->user()`:
 * those would RESOLVE the guard, i.e. parse a token and read the user row on
 * every public request that happens to carry a cookie, and a valid cookie on a
 * foreign mandant's public page would then be answered with 403 instead of the
 * public page. The guard caches its user for the lifetime of the application
 * instance — exactly one request under php-fpm/`artisan serve` (a persistent
 * worker would flush the guards per request, like Octane does), and the whole
 * check is only ever reached after `auth:api` resolved the guard for the
 * current request.
 *
 * **Position.** Appended to the `api` group and spliced into the middleware
 * priority list directly after `SubstituteBindings`, so it runs as the LAST
 * middleware before the controller action: after `auth:api` (it needs the
 * resolved user), after every route-specific rate limiter, and before any
 * mandant-scoped mutation. Deliberately not "first after auth": the codebase
 * already rate-limits BEFORE the login-time mandant check
 * (`throttle:login` → `mayLogInOnCurrentMandant()`), and running here keeps a
 * rejected cross-mandant request inside the `throttle:apply` / `throttle:media`
 * budget instead of opening an unthrottled 403 loop. Route-model binding has
 * already resolved at that point; it is an unscoped `find` that creates and
 * mutates nothing, so a foreign id cannot change any state.
 *
 * **Two exemptions (`EXEMPT_ROUTES`, #6-1-D1).** `POST /api/auth/logout` and
 * `GET /api/auth/me` answer even for an account whose role was JUST revoked.
 * Without that, the revocation the middleware exists for would strand the
 * session: the SPA calls `/auth/me` on every load and `POST /auth/logout` on
 * sign-out, a 403 on both leaves the httpOnly cookie in place and the frontend
 * has no 401-triggered cleanup path — the account would sit on a page it can
 * no longer use until the cookie expires (`JWT_TTL`, 60 min).
 * Neither exemption widens the tenant boundary: `/auth/me` answers with the
 * CALLER's own `UserResource` (own fields, own roles, own media) and never
 * with foreign data, and `/auth/logout` only tears down the caller's own token
 * and cookie. Both are read-or-teardown, not a mandant-scoped write, so the
 * hole this middleware closes is unaffected. Deliberately keyed by route NAME
 * plus method, never by role: no other route is exempt, and the check runs only
 * AFTER the membership test, so members never pay for it.
 */
class EnsureMandantMembership
{
    /**
     * Same wording as the login-time rejection
     * (`AuthController::mayLogInOnCurrentMandant()`): the account simply is
     * not registered on this portal. Identical message for "the role was
     * revoked" and "the token is replayed on a foreign domain" on purpose —
     * the response must not tell an attacker which of the two applies, and the
     * SPA needs no new error string for it.
     */
    public const DENIED_MESSAGE = 'Dieser Account ist für dieses Portal nicht registriert.';

    /**
     * The only two routes that answer for an account without a role in the
     * current mandant: `route name => the method it must be called with`.
     *
     * - `POST /api/auth/logout` — session teardown. This is the only way the
     *   server removes the httpOnly cookie, so denying it would leave a
     *   just-revoked account logged in until the cookie expires.
     * - `GET /api/auth/me` — the caller reads ONLY its own record; no foreign
     *   mandant data can be reached through it.
     *
     * The method is part of the entry so a future route reusing one of the
     * names cannot inherit the exemption by accident. Nothing else is exempt —
     * in particular the un-gated write routes (`apply`, `user/media`,
     * `user/profile`) stay denied, and the exemption is not role-based.
     *
     * @var array<string, string>
     */
    public const EXEMPT_ROUTES = [
        'api.auth.logout' => 'POST',
        'api.auth.me' => 'GET',
    ];

    public function __construct(private readonly AuthManager $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('api');

        // No `auth:api` in this route's middleware (public portal / accreditations
        // / `verify`, and the auth routes that only live outside the group):
        // the guard never resolved a user, and `hasUser()` reports that
        // WITHOUT triggering a resolution. Nothing to check, nothing spent.
        if (! $guard->hasUser()) {
            return $next($request);
        }

        $user = $guard->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        // Console/CLI, seeders, and hosts that map to no mandant are outside the
        // tenant model — the same escape hatch `mayLogInOnCurrentMandant()`
        // uses, so a command or a test without a resolved mandant keeps working.
        $mandantId = MandantContext::currentId();

        if ($mandantId === null) {
            return $next($request);
        }

        if ($user->isMemberOfMandant($mandantId)) {
            return $next($request);
        }

        // After the membership test, not before: a member never reaches this,
        // so `/me` — called on every SPA load — stays free of the extra branch.
        if ($this->isExempt($request)) {
            $this->logExempt($request, $user, $mandantId);

            return $next($request);
        }

        return $this->deny($request, $user, $mandantId);
    }

    /**
     * Whether the request targets one of the two `EXEMPT_ROUTES`. Matched on
     * the resolved route NAME plus the effective method (so Symfony's
     * `_method` override cannot turn a different route into an exempt one), and
     * false when no route was matched at all (a 404 is not a session action).
     */
    public function isExempt(Request $request): bool
    {
        $route = $request->route();

        if (! is_object($route) || ! method_exists($route, 'getName')) {
            return false;
        }

        $name = $route->getName();

        if ($name === null) {
            return false;
        }

        return (self::EXEMPT_ROUTES[$name] ?? null) === $request->method();
    }

    /**
     * Audit trail for the two exemptions: a non-member reaching `/me` or
     * `/logout` is either a just-revoked account or a foreign token replay, and
     * both are worth seeing next to the `deny()` notice. `info`, not `notice` —
     * the answer was NOT a rejection.
     */
    private function logExempt(Request $request, User $user, int $mandantId): void
    {
        Log::info('EnsureMandantMembership: exempt route answered for an account without a role in the current mandant.', [
            'user_id' => $user->getAuthIdentifier(),
            'mandant_id' => $mandantId,
            'host' => $request->getHost(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);
    }

    /**
     * 403 with the stable German message. The reason is logged (auditable:
     * "who tried to act in which mandant from which host") instead of being
     * exposed — same split as `EnsureSameOrigin`.
     */
    private function deny(Request $request, User $user, int $mandantId): JsonResponse
    {
        Log::notice('EnsureMandantMembership: the authenticated account holds no role in the current mandant.', [
            'user_id' => $user->getAuthIdentifier(),
            'mandant_id' => $mandantId,
            'host' => $request->getHost(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        return response()->json(['message' => self::DENIED_MESSAGE], Response::HTTP_FORBIDDEN);
    }
}
