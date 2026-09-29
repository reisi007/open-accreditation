<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserMedia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Hard deletion of one account, DSGVO style: the row and everything that
 * references it go, nothing is anonymised.
 *
 * ## Rows first, files second — the same order the mandant delete uses
 *
 * `MandantController::deleteRowsThenFiles()` documents why (`WF-3-D3`): a file
 * unlink is NOT transactional, so purging before deleting the row leaves a
 * window in which some files are gone and the row is still there. Deleting the
 * row FIRST means a purge that fails afterwards can only leave an UNREFERENCED
 * file behind, which is a storage residue an operator has to collect — the
 * inverse of the alternative, which is a database row pointing at a file that
 * no longer exists. For the account deletion the argument is even stronger:
 * the row MUST go (the user decision is "wirklich löschen wegen DSGVO"), so a
 * stuck file may not be allowed to keep the account alive.
 *
 * ## What the row delete does and does not take with it
 *
 * `role_user`, `user_media`, `applications` and `sub_applications` all carry
 * `cascadeOnDelete()` on `user_id`, so the account's role assignments, its media
 * ROWS, its applications and its sub-applications (Park-/Sitzkarte) go with it.
 * `sessions.user_id` is the one `nullable()->index()` column WITHOUT a foreign
 * key (`0001_01_01_000000:75`), so its rows would survive as orphans pointing at
 * a user id that no longer exists — they are deleted explicitly, inside the same
 * transaction, before the account goes.
 *
 * ## Revocation rests on the DB row, never on the JWT blacklist
 *
 * `JWTGuard::user()` resolves the token subject with a plain
 * `retrieveById($payload['sub'])` (`:107`). Once the row is gone the account is
 * unauthenticable on the very next request, and that keeps holding after a
 * complete cache flush. Nothing here writes a blacklist entry — `logout()`'s
 * blacklist (Weg B, accepted risk A6) is undone by one `cache:clear`, swallows
 * its own failure and reports success, so a deletion that leaned on it would
 * inherit all three weaknesses. `AccountDeletionRevokesAccessImmediatelyTest`
 * and `AccountDeletionTest::test_the_deletion_does_not_go_through_the_jwt_blacklist`
 * are the guards.
 *
 * ## Every printed badge dies with its application — and the caller is told so
 *
 * Each badge's QR verification hangs on its application's `qr_token`. A hard
 * deletion takes that token with it, so an already-printed badge stops
 * verifying. That is the correct DSGVO consequence (the token would otherwise
 * remain a way to re-identify the deleted person) and it is part of the summary
 * the controller returns, not something the UI has to guess.
 *
 * ## The record names the ACTOR as well as the target (accepted risk A5)
 *
 * There is no `audit_logs` table, so the application log is the only place the
 * question "who deleted which account" can ever be answered — and that question
 * has two halves. A line carrying only the target answers "which account",
 * which is the half a `reason: admin` record could answer on its own. The actor
 * is passed in by the caller ({@see delete()}) and logged next to it, with
 * `actor_is_target` stating the relation for the self-service case.
 *
 * ## A file that will not go is a residue, not a 500
 *
 * The account is gone either way; reporting a server error for a leftover file
 * would tell the user his deletion failed when it succeeded. The stuck files are
 * handled by `MediaPurgeRunner` with the bounded-retry contract, logged the
 * moment they are known to be stuck, and returned in the summary so the response
 * can name them.
 */
final class AccountDeletionService
{
    /**
     * `user-media/**` lives on the `private` disk, which `media:prune-orphans`
     * does NOT enumerate (W5 — it walks the managed layout on the `media` disk
     * plus, only behind `--include-legacy`, `mandants/*` and `badge-images/*`).
     * So the leftover advice must name the MANUAL step; promising the reaper
     * here would be a promise nothing keeps, and an operator who believes it
     * never cleans the file up. Same wording as `MandantMediaService::logLeftover()`.
     */
    public const LEFTOVER_ADVICE = 'no automated reaper covers it — delete it manually (`media:prune-orphans` only scans the managed layout on the `media` disk)';

    /**
     * The summary of one deletion. `residue` is the list of files that are still
     * on the disk (empty on the happy path); the counts are what the delete took
     * with it.
     */
    public const SUMMARY_SHAPE = [
        'deleted' => 'bool — false when a concurrent delete won the race',
        'user_id' => 'int|null',
        'email' => 'string|null',
        'name' => 'string|null',
        'mandant_id' => 'int|null',
        'counts' => 'array{applications: int, sub_applications: int, media: int, role_assignments: int, sessions: int}',
        'residue' => 'list<string> — media paths still on the disk',
    ];

    public function __construct(
        private readonly UserMediaService $media,
        private readonly MediaPurgeRunner $purgeRunner,
    ) {}

    /**
     * Delete one account: its sessions rows, the account row with everything
     * that cascades from it, and then the files its media rows referenced.
     *
     * The order inside is fixed and asserted by tests: sessions + row in ONE
     * transaction, files strictly afterwards.
     *
     * ## Why `$actor` is a PARAMETER and not `auth()->user()`
     *
     * Accepted risk A5 makes this log the ONLY place the question "who deleted
     * which account" can be answered, so the actor is not optional information —
     * it is half of what the record exists for. A service that reached into the
     * auth resolver would make that half invisible at every call site (a reader
     * of `$this->deletions->delete($user, 'admin')` could not tell who is
     * recorded), and it would be untestable for the `null` case, which is exactly
     * the case the payload has to render legibly. An explicit parameter makes
     * the caller state the actor, and both callers can: the admin route passes
     * `$request->user()`, the self-service route passes the target itself.
     *
     * The parameter is REQUIRED, not defaulted: a new call site that forgets it
     * is an `ArgumentCountError` at review time, not a silently actor-less entry
     * in the one log that cannot be reconstructed.
     *
     * @param  string  $reason  who asked: `self_service` | `admin`
     * @param  User|null  $actor  the authenticated account that requested the
     *                            deletion — a different account for an admin
     *                            deletion, the target itself for self-service,
     *                            `null` if the caller cannot resolve one (never
     *                            silently: `actor_is_target` then says `false`)
     * @return array<string, mixed> the summary (see `SUMMARY_SHAPE`)
     */
    public function delete(User $user, string $reason, ?User $actor): array
    {
        // The reference snapshot is taken INSIDE the transaction that deletes
        // the row, under a row lock — the same F6 reasoning as the mandant
        // cascade: read outside, it would describe an earlier instant, and a
        // concurrent upload could commit a media row in between whose file
        // nothing would then collect.
        $snapshot = DB::transaction(function () use ($user): ?array {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            /** @var Collection<int, UserMedia> $media */
            $media = $locked->media()->get();

            $counts = [
                'applications' => $locked->applications()->count(),
                'sub_applications' => $locked->subApplications()->count(),
                'media' => $media->count(),
                'role_assignments' => $locked->roleUserAssignments()->count(),
            ];

            // The one column without a foreign key (see the class docblock).
            // Deleted BEFORE the account, inside the same transaction, so the
            // two cannot get out of step.
            $counts['sessions'] = DB::table('sessions')->where('user_id', $locked->id)->delete();

            $locked->delete();

            // Handed back: the file phase works from these instances, never from
            // the database — the rows are gone now.
            return [
                'counts' => $counts,
                'media' => $media,
                'user_id' => $locked->id,
                'email' => $locked->email,
                'name' => $locked->name,
                'mandant_id' => $locked->mandant_id,
            ];
        });

        if ($snapshot === null) {
            // A concurrent delete won the race. It ran the same cascade — rows
            // and files — so there is nothing left for this request to do, and
            // nothing to log as deleted by THIS actor.
            return [
                'deleted' => false,
                'user_id' => $user->id,
                'email' => null,
                'name' => null,
                'mandant_id' => null,
                'counts' => ['applications' => 0, 'sub_applications' => 0, 'media' => 0, 'role_assignments' => 0, 'sessions' => 0],
                'residue' => [],
            ];
        }

        // Rows are gone; only unreferenced files can remain now. One purge per
        // file, so a single stuck file cannot strand the others.
        $purges = [];

        /** @var Collection<int, UserMedia> $rows */
        $rows = $snapshot['media'];

        foreach ($rows as $row) {
            $purges[$row->path] = fn () => $this->media->purge($row);
        }

        $failed = $this->purgeRunner->run($purges, 'a deleted account', self::LEFTOVER_ADVICE);

        $summary = [
            'deleted' => true,
            'user_id' => $snapshot['user_id'],
            'email' => $snapshot['email'],
            'name' => $snapshot['name'],
            'mandant_id' => $snapshot['mandant_id'],
            'counts' => $snapshot['counts'],
            'residue' => array_keys($failed),
        ];

        $this->log($summary, $reason, $actor);

        return $summary;
    }

    /**
     * The mandatory deletion record (accepted risk A5).
     *
     * The application log is the ONLY place the question "who deleted which
     * account" can ever be answered — there is no `audit_logs` table. So the
     * line carries BOTH sides of that question: the actor (`actor_user_id` /
     * `actor_user_email`) and the target (id, email, name, mandant), plus the
     * counts of what went with the account and the residue so a leftover file
     * can be traced back to this deletion.
     *
     * `actor_is_target` exists so a self-service entry does not have to be
     * decoded by comparing two ids: it states the relation outright, which is
     * also what makes an actor-less entry (`null`) distinguishable from a
     * self-service one — both would otherwise print `actor_user_id: null`
     * differences that a log reader has to guess at.
     */
    private function log(array $summary, string $reason, ?User $actor): void
    {
        Log::notice('An account was deleted.', [
            'reason' => $reason,
            'actor_user_id' => $actor?->id,
            'actor_user_email' => $actor?->email,
            'actor_is_target' => $actor !== null && (int) $actor->id === (int) $summary['user_id'],
            'deleted_user_id' => $summary['user_id'],
            'deleted_user_email' => $summary['email'],
            'deleted_user_name' => $summary['name'],
            'deleted_user_mandant_id' => $summary['mandant_id'],
            'applications_deleted' => $summary['counts']['applications'],
            'sub_applications_deleted' => $summary['counts']['sub_applications'],
            'media_rows_deleted' => $summary['counts']['media'],
            'role_assignments_deleted' => $summary['counts']['role_assignments'],
            'sessions_deleted' => $summary['counts']['sessions'],
            'media_files_left_over' => $summary['residue'],
            'leftover_advice' => self::LEFTOVER_ADVICE,
        ]);
    }
}
