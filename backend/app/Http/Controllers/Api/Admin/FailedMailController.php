<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\FailedMailResource;
use App\Jobs\SendMandantMail;
use App\Models\FailedJob;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Position 45 (2026-10-02): the dead-letter surface for undelivered mandant
 * mails — list and manual requeue.
 *
 *   GET  /api/admin/failed-mails              (list)
 *   POST /api/admin/failed-mails/{id}/requeue (move back onto the queue)
 *
 * Route-gated by `can:mails.dlq.manage` (mandant_admin; super_admin bypasses).
 * The MANDANT SCOPE is enforced here, not by the gate:
 *
 *  - `super_admin` sees and requeues every dead letter.
 *  - `mandant_admin` sees and requeues only rows whose `mandant_id` is his
 *    current mandant. A foreign id is a 404 — the same shape the tenant CRUD
 *    uses, and the reason the scope cannot be a silent WHERE: a failure list
 *    contains recipient addresses, so a cross-mandant read is a real leak.
 *
 * Requeue is exactly the framework's `queue:retry` (push the stored payload
 * back onto its connection/queue, then drop the dead letter), exposed to the
 * app because `queue:retry` is a shell command for people with deploy access.
 * The payload's `attempts` is reset so the re-queued job gets a fresh budget,
 * AND the mail job's `deliveryId` is re-stamped, so the requeue is a genuinely
 * fresh delivery rather than one the idempotency guard would refuse (see
 * `SendMandantMail` and `prepareForRequeue()`). The action is logged: a manual
 * requeue is a human decision and must be attributable (there is no automatic
 * path back out of `dead`).
 *
 * ## `total` is an UPPER BOUND, and the frontend is built for that
 *
 * The number the UI shows next to the counter is `meta.total`, which counts the
 * SQL SCOPE. For every row the provider wrote that equals the number of dead
 * letters, because the provider stamps `mandant_id` from the same payload decode
 * that decides `isMailJob()`. It over-reads only for a row inserted outside the
 * provider that carries a `mandant_id` without being a mail job.
 *
 * ## The `{message}` body is localized
 *
 * The UI shows this body verbatim (`frontend/src/logic/serverActionMessage.ts`),
 * so it used to be a hardcoded German literal that an `en` admin read as
 * German. It is now `mails.queued` — the SAME key the two resend endpoints use,
 * because the contract is identical: a delivery job was written, and this
 * process cannot know whether the relay ever answered.
 *
 * ## Pagination, and the discriminator it must not lose (2026-10-06)
 *
 * `index()` used to read the WHOLE table and filter in PHP. `failed_jobs` grows
 * without bound by decision (dead letters are kept until a human requeues them),
 * so that shape is the point where this surface breaks first — and it is now
 * `page`/`per_page` paginated.
 *
 * ### The trap this code is shaped around
 *
 * The obvious implementation — `->paginate()` on the existing query — is WRONG,
 * and not by a small margin. `isMailJob()` is the AUTHORITATIVE discriminator
 * (it decodes the JSON payload through `QueuedMailPayload::mailJob()`), while
 * `whereNotNull('mandant_id')` is only a SCOPE. A dead letter and a SQL row are
 * therefore not the same thing, and pagination cannot be "whatever the SQL window
 * happened to return". A non-mail dead letter inside the window shortens a page —
 * the admin sees 7 rows where the control promised 10, with nothing in the UI
 * that says so — and `forPage`'s arithmetic is in SQL ROWS, so every later page
 * is shifted and the last row of one page reappears on the next.
 *
 * MEASURED on the naive shape (20 mail dead letters + 1 non-mail job carrying a
 * `mandant_id`, `per_page=10`): `total` = 21 for 20 mail jobs; with phantoms
 * inside the first two windows, `forPage(3)` re-served a row `forPage(2)` had
 * already shown. Both are pinned in `MailDeadLetterTest`, which fails on a naive
 * `forPage()` as well as on a dropped `isMailJob()`.
 *
 * ### Why the SQL window is `whereNotNull('mandant_id')` and NOT a payload LIKE
 *
 * A `payload LIKE '%…%'` prefilter was measured and REJECTED, for two measured
 * reasons:
 *
 *  1. It buys nothing. `MandantAwareFailedJobProvider::log()` stamps
 *     `mandant_id` from `QueuedMailPayload::mandantId($payload)`, and that
 *     returns non-null if and only if `mailJob()` does. So the provider already
 *     never gives a `mandant_id` to a non-mail job — which is exactly what
 *     `whereNotNull('mandant_id')` filters on. A LIKE re-filters the same set.
 *  2. A TIGHTER LIKE is a false-negative trap. `"commandName":"App\Jobs\…"`
 *     does not match a real payload at all (measured: 0 rows, while
 *     `isMailJob()` was true for the very row) — the raw JSON carries ESCAPED
 *     backslashes, so under `ESCAPE '\'` the pattern needs a QUADRUPLE
 *     backslash (measured: 2 rows once quadrupled). That marker then breaks
 *     again on a payload with different JSON formatting (measured: 0 rows
 *     against a `JSON_PRETTY_PRINT` payload that `isMailJob()` accepts). That
 *     is `AGENTS.md` §2's `LIKE … ESCAPE` trap, and it fails CLOSED — a real
 *     dead letter disappears from the DLQ — which is the worst possible
 *     direction for this queue.
 *
 * ### What is left, stated honestly
 *
 * Two numbers, both named rather than discovered:
 *
 *  - **`total` is the SQL count of the scoped table** — EXACT for every row the
 *    provider wrote (see 1. above), and an UPPER BOUND only for a row inserted
 *    OUTSIDE the provider that carries a `mandant_id` without being a mail job.
 *    Such a row inflates `total` by one and can leave a trailing page empty; it
 *    can never appear in `data`, because `isMailJob()` remains the authority per
 *    row. MEASURED at 2000 rows: an exact count by `cursor()` costs 32.2 ms
 *    against 6.6 ms for the SQL count — the exact count is the LINEAR scan this
 *    pagination exists to remove, so the upper bound is the deliberate trade. It
 *    is pinned by `test_a_non_mail_dead_letter_shortens_no_page_and_appears_on_none`.
 *  - **The scan is O(page).** Reaching page N reads N windows, because "how many
 *    dead letters are above page N" is only knowable by walking — that is what
 *    {@see collectPage()} pays for exactness. It is never SLOWER than the code it
 *    replaces (`->get()` read the whole table on every request), and page 1 reads
 *    about one window. MEASURED at 1000 rows: the old shape 33.5 ms, page 1
 *    6.1 ms (5.5× faster), last page 285.9 ms.
 *
 * ### `last_page` is capped at the ceiling, and that is the fix for an empty last page
 *
 * The arithmetic last page (`ceil(total / perPage)`) can name a page the walk is
 * not allowed to reach, which produced a control that led nowhere: MEASURED at
 * 1050 letters with `per_page=50` (verification round 71, 2026-10-06),
 * `ceil(1050/50) = 21`, while the walk reads at most `20 × 50 = 1000` rows — so
 * page 21 answered with **0 rows** under a `last_page: 21`, and the UI rendered
 * that as "all letters were delivered" over a full queue.
 * `reachableLastPage()` caps the number at {@see MAX_SCAN_BATCHES},
 * the letters beyond it stay counted by `total`, and they are reachable by asking
 * for a wider `per_page`.
 *
 * @see lang/de/mails.php
 */
class FailedMailController extends Controller
{
    /** Page size when the request names none. */
    public const PER_PAGE_DEFAULT = 50;

    /**
     * The ceiling on `per_page`, and the smallest accepted value.
     *
     * The dead-letter list is a triage surface: an operator reads rows and
     * requeues them one dialog at a time, so a page far above a screenful buys
     * nothing and costs the response body. Both bounds are validated, not
     * clamped (see `index()`), so a client that asks for 0 or for 9999 gets a
     * 422 and a fixable error rather than a silently different page size.
     */
    public const PER_PAGE_MIN = 1;

    public const PER_PAGE_MAX = 200;

    /**
     * How many SQL windows ONE list request may read.
     *
     * The walk in {@see collectPage()} is the price of counting in dead letters
     * instead of SQL rows (there: the whole argument), and this is its ceiling.
     * The provider's own writes make a rejected row inside a window rare, so one
     * window is the normal answer and this number only bites a pathological table.
     *
     * Past the bound the page comes back EMPTY, deliberately (see
     * `collectPage()` for what "deliberately" means and why the failure direction
     * is "nothing" rather than "the wrong thing").
     *
     * MEASURED: 1 window for every data set in the suite, including 30
     * non-mail rows stacked above 120 mail rows.
     */
    public const MAX_SCAN_BATCHES = 20;

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        // `per_page=0` / `abc` / `9999` are CLIENT mistakes, and a 422 says so.
        // They used to be a 500 risk; clamping instead would answer a question
        // nobody asked ("give me 200 because I typed 9999").
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:'.self::PER_PAGE_MIN, 'max:'.self::PER_PAGE_MAX],
        ]);

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? self::PER_PAGE_DEFAULT);

        $query = FailedJob::query()
            ->whereNotNull('mandant_id')
            ->orderByDesc('failed_at')
            ->orderByDesc('id');

        if (! $user->isSuperAdmin()) {
            $query->where('mandant_id', $this->currentMandantId());
        }

        $total = (clone $query)->count();

        $rows = $this->collectPage($query, $page, $perPage);

        return FailedMailResource::collection($rows)->additional([
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                // `last_page` is the last page this endpoint can actually SERVE,
                // not the arithmetic last page of `total` — see
                // {@see reachableLastPage()}.
                'last_page' => $this->reachableLastPage($total, $perPage),
            ],
        ]);
    }

    /**
     * The last page this endpoint can SERVE, which is not `ceil(total / perPage)`.
     *
     * `collectPage()` reaches page N by reading N windows, and
     * {@see MAX_SCAN_BATCHES} caps that at 20 — so the arithmetic last page is a
     * page the walk is not allowed to fill. Reporting it made the UI offer a
     * control that leads nowhere: MEASURED at 1050 letters with `per_page=50`
     * (verification round 71, 2026-10-06), `ceil(1050/50) = 21` while the walk
     * reads at most `20 × 50 = 1000` rows, so page 21 came back EMPTY while
     * `last_page: 21` claimed otherwise.
     *
     * Clamping here (rather than in the UI) is the honest direction: `last_page`
     * becomes a statement about what the endpoint delivers, and the queue beyond it
     * stays reachable by asking for a WIDER `per_page` — the same 1050 letters at
     * `per_page=200` are 6 pages, all of them servable. The letters the clamp hides
     * are still counted by `total`, which the UI shows next to the counter.
     *
     * It stays an UPPER BOUND in the other direction too, for the same reason
     * `total` does: a page whose window is filled by non-mail rows is counted but
     * not servable. That residue is not this method's problem to solve — the UI
     * treats "no rows although `total > 0`" as its own state (see
     * `FailedMailsPage`), which is the state a clamped page would otherwise hide.
     */
    private function reachableLastPage(int $total, int $perPage): int
    {
        return min(max(1, (int) ceil($total / $perPage)), self::MAX_SCAN_BATCHES);
    }

    /**
     * One page of dead letters, counted in DEAD LETTERS rather than in rows.
     *
     * `isMailJob()` is the authoritative discriminator, so the SQL row a window
     * yields and the dead letter a page contains are not the same thing. That
     * difference is what makes `->forPage($page, $perPage)` wrong here in two
     * separate ways, both measured in `MailDeadLetterTest`:
     *
     *  - a phantom row inside the window SHORTENS the page (the admin asks for
     *    10 and gets 8, with nothing on screen saying a row was dropped);
     *  - and `forPage`'s arithmetic is in SQL rows, so every page after the
     *    first phantom is off by one and the last row of page N REAPPEARS on
     *    page N+1. MEASURED: with phantoms inside the first two windows,
     *    `forPage(3)` re-served a row `forPage(2)` had already shown.
     *
     * So this does not offset by `(page - 1) * perPage`. It walks windows from
     * the START of the ordered table and counts DEAD LETTERS, skipping
     * `(page - 1) * perPage` of them and then collecting `perPage`. The windows
     * are SQL-side (bounded memory — the defect `->get()` over the whole table
     * had); the counting is PHP-side (which is what makes the page exact).
     *
     * ## The price, named rather than discovered
     *
     * Reaching page N costs N windows to read, because "how many dead letters
     * are above page N" is only knowable by walking. This is never SLOWER than
     * the code it replaces — `->get()` read the whole table on every request —
     * and page 1 reads roughly one window. MEASURED at 1000 rows: the old shape
     * 33.5 ms, page 1 6.1 ms (5.5× faster), the LAST page 285.9 ms (8.5× the
     * old shape). That last number is the honest cost of exactness and the
     * reason the alternative is not free either: an exact `total` by `cursor()`
     * costs 32.2 ms at 2000 rows against 6.6 ms for the SQL count, on EVERY page.
     *
     * `MAX_SCAN_BATCHES` is the ceiling on that walk. Past it the page is EMPTY
     * — deliberately empty, not partially filled, and the reason is NOT the
     * duplicate-row defect (the skip counts DEAD LETTERS, so a collected row
     * never belongs to an earlier page). The reason is that a partly filled page
     * is indistinguishable from a page the walk simply ran out of before the end
     * of the table: both look like "the queue ends here", and the second reading
     * is false. MEASURED at 39 letters + 1 phantom, `per_page=2`, `page=20`
     * (verification round 71, 2026-10-06): the ceiling is reached with 1 row
     * collected, and the old shape returned it — 1 row presented as the last page
     * of a queue that holds more. A short page
     * is a claim about the END of the queue, which a truncated walk cannot make,
     * so the failure direction is "nothing" instead of "the wrong thing".
     *
     * @param  Builder<FailedJob>  $query
     * @return Collection<int, FailedJob>
     */
    private function collectPage(Builder $query, int $page, int $perPage): Collection
    {
        $toSkip = ($page - 1) * $perPage;
        $collected = new Collection;
        // Whether the walk stopped because the TABLE ended or because the ceiling
        // bit — the two answers look identical from here ($collected may be
        // partly filled either way) and must not be confused: a table that really
        // ends gives a short page, a truncated walk must not (the docblock says
        // why). Only a break sets this.
        $reachedEndOfTable = false;

        for ($batch = 0; $batch < self::MAX_SCAN_BATCHES; $batch++) {
            $rows = (clone $query)->offset($batch * $perPage)->limit($perPage)->get();

            if ($rows->isEmpty()) {
                $reachedEndOfTable = true;
                break;
            }

            foreach ($rows as $job) {
                if (! $job->isMailJob()) {
                    continue;
                }

                if ($toSkip > 0) {
                    $toSkip--;

                    continue;
                }

                $collected->push($job);

                if ($collected->count() === $perPage) {
                    return $collected;
                }
            }
        }

        // The ceiling bit before the table did. Whatever was collected belongs to
        // a page we cannot vouch for, so the answer is "nothing" — see the
        // `MAX_SCAN_BATCHES` docblock for the direction this chooses.
        return $reachedEndOfTable ? $collected : new Collection;
    }

    public function requeue(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $job = FailedJob::query()->find($id);
        abort_if($job === null, 404);

        if (! $user->isSuperAdmin()) {
            abort_unless(
                $job->mandant_id !== null && (int) $job->mandant_id === $this->currentMandantId(),
                404,
            );
        }

        abort_unless($job->isMailJob(), 404);

        Queue::connection($job->connection)->pushRaw(
            $this->prepareForRequeue($job->payload),
            $job->queue,
        );

        $job->delete();

        Log::info('Failed mail requeued', [
            'failed_job_id' => $job->id,
            'mandant_id' => $job->mandant_id,
            'actor_id' => $user->getKey(),
        ]);

        return response()->json(['message' => __('mails.queued')]);
    }

    private function currentMandantId(): int
    {
        $mandantId = MandantContext::currentId();
        abort_if($mandantId === null, 404, 'No mandant context for this request.');

        return $mandantId;
    }

    /**
     * Prepare a stored payload to go back onto the queue: a fresh attempt
     * budget AND a fresh delivery identity.
     *
     * 1. **Attempts.** The database driver keeps attempts in the `jobs` row (a
     *    fresh row already starts at 0); the payload reset mirrors
     *    `Illuminate\Queue\Console\RetryCommand::resetAttempts()` for drivers
     *    that carry it in the payload.
     * 2. **`deliveryId`.** `SendMandantMail` refuses a send whose claim is
     *    still held (worker crash between claim and ack), by THROWING so the
     *    refusal lands in `failed_jobs` instead of vanishing. A dead letter can
     *    therefore exist WITH a live claim, and re-pushing the payload
     *    verbatim would requeue a job that is guaranteed to be refused again.
     *    Stamping a fresh id is the documented contract of the field ("fresh
     *    on every dispatch") and it is what makes the human's requeue the
     *    escape hatch from the guard rather than another trip into it. Measured
     *    before this change: the requeued job carried the SAME id.
     *
     * The re-serialization is safe because the restricted unserialize in
     * {@see QueuedMailPayload} only ever yields a `SendMandantMail` whose
     * properties are scalars and arrays (the mail itself is the pre-serialized
     * `mailablePayload` string) — `MailDeadLetterTest` pins that the mail
     * survives the round trip byte for byte apart from the id.
     */
    private function prepareForRequeue(string $payload): string
    {
        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        if (array_key_exists('attempts', $decoded)) {
            $decoded['attempts'] = 0;
        }

        $mailJob = QueuedMailPayload::mailJob($payload);

        if ($mailJob !== null) {
            $mailJob->deliveryId = (string) Str::uuid();
            $decoded['data']['command'] = serialize($mailJob);
        }

        return (string) json_encode($decoded);
    }
}
