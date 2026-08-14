<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\BlacklistResource;
use App\Models\Blacklist;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blacklist CRUD (P3e) of the current mandant.
 *
 * The route group is guarded by `can:accreditations.manage`, but blacklists
 * are a mandant-level resource: only super_admin and mandant_admin may touch
 * them — a team_admin holding `accreditations.manage` is rejected with 403
 * here (his own team scope is not applicable to a Verband-wide blacklist).
 *
 *   GET    /api/admin/blacklists?search=  mandant-scoped entries, newest first
 *   POST   /api/admin/blacklists          {email?, domain?, note?} → 201
 *   DELETE /api/admin/blacklists/{id}     mandant-scoped → 204 (foreign → 404)
 *
 * Validation: at least one of `email`/`domain` (422), `email` must be a valid
 * address, `domain` a valid hostname without scheme/port, and the
 * `(mandant_id, email)` / `(mandant_id, domain)` unique constraints reject
 * duplicates (422). Input is normalized to lowercase/trimmed so the
 * case-insensitive blacklist matching (`AllocationRules::isBlacklisted`)
 * stays consistent.
 */
class BlacklistController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantLevelScope($request, $mandantId);

        $query = Blacklist::query()->forMandant($mandantId);

        if ($request->filled('search')) {
            $term = $this->escapeLike((string) $request->input('search'));
            $query->where(function (Builder $q) use ($term) {
                $q->whereRaw("blacklists.email like ? escape '\\'", ["%{$term}%"])
                    ->orWhereRaw("blacklists.domain like ? escape '\\'", ["%{$term}%"])
                    ->orWhereRaw("blacklists.note like ? escape '\\'", ["%{$term}%"]);
            });
        }

        return BlacklistResource::collection($query->orderByDesc('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantLevelScope($request, $mandantId);

        $validated = $request->validate([
            'email' => ['nullable', 'string', 'email'],
            'domain' => ['nullable', 'string', 'regex:/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/i'],
            'note' => ['nullable', 'string'],
        ]);

        $email = $validated['email'] ?? null;
        $domain = $validated['domain'] ?? null;

        if ($email === null && $domain === null) {
            throw ValidationException::withMessages([
                'email' => 'Either an email or a domain is required.',
                'domain' => 'Either an email or a domain is required.',
            ]);
        }

        $email = $email !== null ? Str::lower(trim($email)) : null;
        $domain = $domain !== null ? Str::lower(trim($domain)) : null;

        if ($email !== null && Blacklist::query()->where('mandant_id', $mandantId)->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'This email is already blacklisted.',
            ]);
        }

        if ($domain !== null && Blacklist::query()->where('mandant_id', $mandantId)->where('domain', $domain)->exists()) {
            throw ValidationException::withMessages([
                'domain' => 'This domain is already blacklisted.',
            ]);
        }

        try {
            $blacklist = Blacklist::create([
                'mandant_id' => $mandantId,
                'email' => $email,
                'domain' => $domain,
                'note' => $validated['note'] ?? null,
            ]);
        } catch (QueryException $e) {
            // The unique constraints are the authoritative stop for the race
            // between the explicit checks above and the insert.
            if (stripos($e->getMessage(), 'unique') !== false) {
                throw ValidationException::withMessages([
                    'email' => 'This email or domain is already blacklisted.',
                ]);
            }

            throw $e;
        }

        return (new BlacklistResource($blacklist))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, Blacklist $blacklist): Response
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantLevelScope($request, $mandantId);

        $blacklist = Blacklist::query()->forMandant($mandantId)->findOrFail($blacklist->id);

        $blacklist->delete();

        return response()->noContent();
    }

    /**
     * Blacklists are a mandant-level resource: super_admin (global) and
     * mandant_admin (current mandant) only. A team_admin who passes the
     * `can:accreditations.manage` route gate is rejected here (403) — his
     * team scope does not apply to the Verband-wide blacklist.
     */
    private function assertMandantLevelScope(Request $request, int $mandantId): void
    {
        $user = $request->user();
        abort_if($user === null, 401);

        abort_unless($user->isSuperAdmin() || $user->isMandantAdmin($mandantId), 403);
    }

    /**
     * Escape LIKE wildcards so a search for literal `%`/`_` does not act as a
     * pattern. Applied with an explicit `ESCAPE '\'` clause (portable across
     * Postgres and SQLite — see the PortalController pattern).
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
