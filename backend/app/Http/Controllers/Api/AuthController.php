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
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $mandant = MandantContext::current();

        if ($mandant === null) {
            return response()->json([
                'message' => 'Registrierung ist für diese Domain nicht möglich.',
            ], 422);
        }

        return DB::transaction(function () use ($validated, $mandant): JsonResponse {
            $user = User::create([
                'name' => $validated['name'],
                'email' => strtolower($validated['email']),
                'password' => $validated['password'],
                'email_verified_at' => null,
                'activation_token' => Str::random(64),
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
                activationUrl: $this->activationUrl($user->activation_token),
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
     */
    public function activate(string $token): JsonResponse
    {
        $user = User::query()->where('activation_token', $token)->first();

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
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', strtolower($credentials['email']))->first();

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

    private function activationUrl(string $token): string
    {
        return rtrim(config('app.url'), '/').'/api/auth/activate/'.$token;
    }
}
