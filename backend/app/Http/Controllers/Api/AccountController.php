<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mandant;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Self-service account surface: the confirmation-dialog data and the deletion
 * itself.
 *
 * ## Why there is no gate on this route
 *
 * The target is `$request->user()` — the very account the JWT was minted for.
 * A `can:` gate here would evaluate a permission of the account against ITSELF
 * and could only ever produce a second source of "you may not do that" for a
 * request whose subject is already fixed and already authenticated. The
 * `user` role holds `accreditations.self` and nothing else, so self-service
 * deletion needs no role, no matrix entry and no gate. `auth:api` is the whole
 * authorisation, and the route says so in its own comment.
 *
 * ## What the caller gets, and why the counts have to exist BEFORE the delete
 *
 * A confirmation dialog must be able to name the account and say how many
 * applications go with it. That number cannot be produced after the delete —
 * the rows are gone, and a count read afterwards is always zero. So
 * `GET /api/user/account` answers the identity plus every count the dialog
 * needs, and the delete itself returns the same counts measured at deletion
 * time (they are the authoritative numbers, taken inside the transaction).
 */
class AccountController extends Controller
{
    public function __construct(private readonly AccountDeletionService $deletions) {}

    /**
     * GET /api/user/account — identity plus the counts the confirmation dialog
     * has to name.
     *
     * 200 `{data: {id, name, email, mandant_id, mandant_name, applications_count,
     * sub_applications_count, media_count}}`.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'mandant_id' => $user->mandant_id,
                'mandant_name' => Mandant::query()->whereKey($user->mandant_id)->value('name'),
                'applications_count' => $user->applications()->count(),
                'sub_applications_count' => $user->subApplications()->count(),
                'media_count' => $user->media()->count(),
            ],
        ]);
    }

    /**
     * DELETE /api/user/account — hard-delete the caller's own account (DSGVO).
     *
     * 200 `{message, data: {applications_deleted, sub_applications_deleted,
     * media_files_deleted, role_assignments_deleted, sessions_deleted,
     * media_files_left_over: string[]}}`.
     *
     * The httpOnly JWT cookie is cleared on the way out. Clearing the cookie is
     * NOT what revokes the access — the account is already gone from the
     * database, so the very next request with that token answers 401 whatever
     * the cookie does (Weg A in `AccountDeletionRevokesAccessImmediatelyTest`).
     * The cookie is only cleared so the browser stops sending a token that can
     * never succeed again.
     *
     * The deletion log (A5) gets `$user` as the actor, i.e. the target is
     * passed as ITSELF: the record then says so explicitly through
     * `actor_is_target`, instead of leaving a reader to compare two ids to find
     * out whether "the actor" was a third party.
     */
    public function destroy(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $summary = $this->deletions->delete($user, 'self_service', $user);

        return response()->json([
            'message' => 'Dein Konto und alle zugehörigen Daten wurden gelöscht.',
            'data' => [
                'applications_deleted' => $summary['counts']['applications'],
                'sub_applications_deleted' => $summary['counts']['sub_applications'],
                'media_files_deleted' => $summary['counts']['media'] - count($summary['residue']),
                'role_assignments_deleted' => $summary['counts']['role_assignments'],
                'sessions_deleted' => $summary['counts']['sessions'],
                'media_files_left_over' => $summary['residue'],
            ],
        ])->withCookie(cookie()->forget(config('jwt.cookie_key_name')));
    }
}
