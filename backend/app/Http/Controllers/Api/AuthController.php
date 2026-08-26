<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Mail\ActivationMail;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Validity of a generated activation token.
     */
    public const ACTIVATION_TTL_HOURS = 24;

    /**
     * POST /api/auth/register
     *
     * Creates a user inside the current mandant (role `user` scoped to the
     * mandant), generates an activation token and sends the activation mail.
     * The account only becomes login-capable after the mail link was clicked.
     *
     * BE-R1: emails are unique PER MANDANT, not globally — the same person
     * may register the same address on two different mandant domains and gets
     * two independent accounts. The uniqueness scope is anchored on
     * `users.mandant_id`, which is set from `MandantContext` (host-derived,
     * never user input).
     */
    public function register(Request $request): JsonResponse
    {
        // BE-R1: emails are unique PER MANDANT, not globally — the same person
        // may register the same address on two different mandant domains and
        // gets two independent accounts. The uniqueness scope is anchored on
        // `users.mandant_id`, which comes from `MandantContext`
        // (host-derived via MandantContextMiddleware, never user input).
        //
        // Order matters: base field validation runs FIRST (so an empty payload
        // yields the usual field errors regardless of the domain), then the
        // mandant guard rejects domains without a resolvable mandant.
        $mandant = MandantContext::current();

        // Lowercase BEFORE validation so the per-mandant unique rule cannot be
        // bypassed with a case variant ("Alice@X.COM" vs "alice@x.com") — both
        // Postgres and SQLite compare text case-sensitively here. Registration
        // already persisted lowercased emails before BE-R1.
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                // Scoped uniqueness only applies where a mandant could be
                // resolved; without one the guard below rejects anyway.
                ...($mandant === null ? [] : [
                    Rule::unique('users', 'email')->where('mandant_id', $mandant->id),
                    // RV-S3: a GLOBAL account (mandant_id null, e.g. the
                    // bootstrap super admin) must never be shadowed by a
                    // domain-local registration — findLoginUser prefers the
                    // current mandant's row, which would lock the global
                    // account out of its login on this domain. Equivalent
                    // portable query (works on Postgres and SQLite alike),
                    // expressed as a closure so the failure can carry its own
                    // German message instead of the generic "already taken".
                    function (string $attribute, mixed $value, Closure $fail): void {
                        if (User::query()->where('email', $value)->whereNull('mandant_id')->exists()) {
                            $fail('Für diese E-Mail-Adresse existiert bereits ein systemweites Konto. Bitte registriere dich mit einer anderen Adresse.');
                        }
                    },
                ]),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if ($mandant === null) {
            return response()->json([
                'message' => 'Registrierung ist für diese Domain nicht möglich.',
            ], 422);
        }

        return DB::transaction(function () use ($validated, $mandant, $request): JsonResponse {
            // F4: the raw one-time token is sent to the user (mail/URL) but
            // only its sha256 digest (64 hex chars — fits the 64-char column)
            // is persisted, so a leaked users-table dump yields no usable
            // activation links.
            $token = Str::random(64);

            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                // BE-R1: bind the account to the current mandant — the
                // uniqueness anchor for (mandant_id, email) and the login
                // lookup scope.
                'mandant_id' => $mandant->id,
                'password' => $validated['password'],
                'email_verified_at' => null,
                'activation_token' => hash('sha256', $token),
                'activation_token_expires_at' => now()->addHours(self::ACTIVATION_TTL_HOURS),
            ]);

            $role = Role::query()->where('slug', UserRole::USER->value)->firstOrFail();

            RoleUser::create([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'mandant_id' => $mandant->id,
                'team_id' => null,
            ]);

            Mail::to($user->email)->send(new ActivationMail(
                name: $user->name,
                activationUrl: $this->activationUrl($token, $request),
            ));

            return response()->json([
                'message' => 'Registrierung erfolgreich. Bitte prüfe deine E-Mail zur Aktivierung.',
            ], 201);
        });
    }

    /**
     * GET /api/auth/activate/{token}
     *
     * Validates the one-time activation token (GET so the mail link works
     * directly in a browser), sets `email_verified_at` and consumes the token.
     * The lookup uses the sha256 digest of the raw token (F4) — the DB never
     * stores the raw token.
     */
    public function activate(string $token): JsonResponse
    {
        $user = User::query()->where('activation_token', hash('sha256', $token))->first();

        if ($user === null) {
            return response()->json([
                'message' => 'Der Aktivierungslink ist ungültig.',
            ], 404);
        }

        if ($user->activation_token_expires_at === null || $user->activation_token_expires_at->isPast()) {
            return response()->json([
                'message' => 'Der Aktivierungslink ist abgelaufen. Bitte registriere dich erneut.',
            ], 410);
        }

        $user->update([
            'email_verified_at' => now(),
            'activation_token' => null,
            'activation_token_expires_at' => null,
        ]);

        return response()->json([
            'message' => 'Konto erfolgreich aktiviert. Du kannst dich jetzt anmelden.',
        ]);
    }

    /**
     * POST /api/auth/login
     *
     * Validates credentials and returns the JWT in the httpOnly `accr_jwt`
     * cookie. Only activated users may log in; accounts of another mandant
     * are rejected (mandant isolation).
     *
     * BE-R1: since emails are unique per mandant (not globally), the lookup
     * is HOST-SCOPED — on a mandant domain only that mandant's accounts (plus
     * global `mandant_id = null` accounts such as the bootstrap super admin)
     * are addressable. An email that exists only on ANOTHER mandant's domain
     * yields the generic 401, exactly like an unknown email: this deliberately
     * removes the previous cross-tenant existence oracle (401 vs 403).
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = $this->findLoginUser(strtolower(trim($credentials['email'])));

        if ($user === null || ! Hash::check($credentials['password'], $user->password)) {
            return response()->json([
                'message' => 'Ungültige Zugangsdaten.',
            ], 401);
        }

        if ($user->email_verified_at === null) {
            return response()->json([
                'message' => 'Das Konto ist noch nicht aktiviert. Bitte prüfe deine E-Mail.',
            ], 403);
        }

        if (! $this->mayLogInOnCurrentMandant($user)) {
            return response()->json([
                'message' => 'Dieser Account ist für dieses Portal nicht registriert.',
            ], 403);
        }

        return $this->respondWithToken(auth('api')->login($user));
    }

    /**
     * Resolve the login candidate for an email under the current host.
     *
     * With a resolved mandant context (BE-R1): prefer THIS mandant's account
     * for the email (the domain-local identity), falling back to a global
     * account (`mandant_id = null`, e.g. the bootstrap super admin). The
     * `(mandant_id, email)` unique guarantees at most one mandant-scoped hit;
     * several global rows sharing one email are legal via NULL semantics but
     * pathological — the seeder prevents them.
     *
     * WITHOUT a resolved context (CLI, tests, hosts outside any mandant): the
     * legacy unrestricted email lookup applies. Authorization still runs
     * through `mayLogInOnCurrentMandant()` afterwards in every case.
     */
    private function findLoginUser(string $email): ?User
    {
        $mandantId = MandantContext::currentId();

        if ($mandantId === null) {
            return User::query()->where('email', $email)->first();
        }

        return User::query()->where('email', $email)->where('mandant_id', $mandantId)->first()
            ?? User::query()->where('email', $email)->whereNull('mandant_id')->first();
    }

    /**
     * POST /api/auth/logout
     *
     * Invalidates the current JWT (blacklist) and removes the cookie.
     */
    public function logout(): JsonResponse
    {
        auth('api')->logout();

        return response()->json([
            'message' => 'Erfolgreich abgemeldet.',
        ])->withCookie(cookie()->forget(config('jwt.cookie_key_name')));
    }

    /**
     * GET /api/auth/me
     *
     * The currently authenticated user (core fields + roles + media).
     */
    public function me(Request $request): UserResource
    {
        /** @var User $user */
        $user = auth('api')->user();

        return new UserResource($user->fresh(['roles', 'media']));
    }

    /**
     * A user may log in on the current host only when they hold at least one
     * role for the current mandant — or are the global super admin. Super
     * admins have no mandant scope and are therefore allowed everywhere.
     */
    private function mayLogInOnCurrentMandant(User $user): bool
    {
        $mandantId = MandantContext::currentId();

        if ($mandantId === null) {
            return true;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->roleUserAssignments()->forMandant($mandantId)->exists();
    }

    /**
     * Build the activation link for the activation mail.
     *
     * The host must come from the current mandant's own domain, not the static
     * `config('app.url')`: in the multi-tenant setup every mandant runs on its
     * own domain (D3/D6), so a link pointing at the primary domain would land
     * the user on the wrong tenant and activation/login fails with a
     * Cross-Mandant 403.
     *
     * Fallback chain: current mandant's first domain host → `config('app.url')`
     * host → request host. The scheme always follows `config('app.url')`
     * (https in prod, http in local).
     */
    private function activationUrl(string $token, ?Request $request = null): string
    {
        $baseUrl = (string) config('app.url');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';

        $host = MandantContext::current()?->domains()->first()?->hostname
            ?? parse_url($baseUrl, PHP_URL_HOST);

        if ($host === null || $host === '') {
            $host = ($request ?? request())->getHost();
        }

        return $scheme.'://'.$host.'/api/auth/activate/'.$token;
    }
}
