<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\BadgeImage;
use App\Models\Mandant;
use App\Rules\ValidUtf8;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin CRUD for mandants (Verbände). Guarded by `can:mandants.manage`
 * (super_admin-only permission — no other role holds it in the matrix).
 */
class MandantController extends Controller
{
    /**
     * Attempts per media purge in the delete cascade, see `purgeWithRetry()`.
     * Bounded: a file that is genuinely unremovable must surface as a 500
     * quickly instead of holding the request open.
     */
    private const PURGE_ATTEMPTS = 3;

    /**
     * Backoff before the second and third attempt, multiplied by the attempt
     * number (so the waits are 50 ms + 100 ms). Long enough to ride out a
     * transient filesystem error, short enough to stay invisible in a request.
     */
    private const PURGE_RETRY_DELAY_MICROSECONDS = 50_000;

    public function __construct(
        private readonly MandantMediaService $media,
        private readonly EventTypeMediaService $eventTypes,
        private readonly BadgeImageService $badges,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return MandantResource::collection(
            Mandant::query()
                ->with('domains')
                ->withCount('teams')
                ->orderBy('name')
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules(forCreate: true));

        $mandant = Mandant::create($validated);

        return (new MandantResource($mandant))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Mandant $mandant): MandantResource
    {
        return new MandantResource($mandant);
    }

    public function update(Request $request, Mandant $mandant): MandantResource
    {
        $validated = $request->validate($this->rules($mandant));

        // Distinguish "key absent" (no-op) from "key present with null"
        // (explicit clear) via `$request->has()`: in a JSON payload a present
        // null key reaches the request data with a null value, while an absent
        // key simply does not exist. An array payload is a partial merge over
        // the stored config; the password is only replaced when a non-empty
        // string arrives (missing/null/empty keep the stored value).
        if ($request->has('smtp_config')) {
            $incoming = $validated['smtp_config'] ?? null;

            if ($incoming === null) {
                $validated['smtp_config'] = $this->clearedSmtpConfig();
            } else {
                $stored = (array) ($mandant->smtp_config ?? []);
                $password = $incoming['password'] ?? null;
                unset($incoming['password']);

                if (is_string($password) && $password !== '') {
                    $stored['password'] = $password;
                }

                $validated['smtp_config'] = array_merge($stored, $incoming);
            }
        }

        $mandant->update($validated);

        return new MandantResource($mandant);
    }

    public function destroy(Mandant $mandant): Response
    {
        if ($mandant->is_primary) {
            return response()->json([
                'message' => 'Der primäre Mandant kann nicht gelöscht werden.',
            ], 422);
        }

        if ($mandant->teams()->exists()) {
            return response()->json([
                'message' => 'Der Mandant besitzt noch Teams und kann nicht gelöscht werden.',
            ], 409);
        }

        // Drop the cached host→mandant mappings for every routed domain, so a
        // re-created mandant under the same hostname resolves against the
        // database again instead of the stale (deleted) mapping.
        foreach ($mandant->domains()->get() as $domain) {
            MandantContext::forgetHost($domain->hostname);
        }

        // The `trustHosts` allow-list holds the full hostname list under its
        // own cache key (MANDANTS_CACHE_TTL = 3600 s by default). Deleting a
        // mandant removes its domains, so the list is stale as well: without
        // this drop the deleted mandant's hostname stays allow-listed for up
        // to an hour — and MandantContextMiddleware then resolves it against
        // nothing, i.e. a 404 that hides a deleted tenant instead of the 400
        // an unknown host should get. Same invalidation as
        // `MandantDomainController` on domain create/delete.
        MandantContext::forgetHostnames();

        // The DB cascade (event_types, badge_images) removes the rows but never
        // the files. Purge every public-media file of the mandant synchronously
        // (original + `.webp` sibling) so deleting a mandant leaves no orphan
        // behind; the weekly `media:prune-orphans` is only a safety net for
        // historical drift. Teams cannot exist here (409 above), so their media
        // is already gone.
        //
        // ORDER: rows first, files second (WF-3-D3). Every purge can raise
        // (R-D7 — an unremovable file keeps its reference), and file unlinks
        // are NOT transactional: a `DB::transaction()` around the cascade
        // cannot roll an unlink back, it can only roll the row writes back. So
        // purging before deleting the row left a real window — the reported
        // trigger (a brand logo stuck behind a read-only bind mount, every
        // child file removable) unlinked every event-type and badge file, then
        // the brand purge raised: HTTP 500, all rows intact, and the live tenant
        // served broken images off dangling `event_types.logo_path` /
        // `badge_images.path` values. Deleting the row FIRST closes it: a purge
        // that fails after the row is gone can only leave an UNREFERENCED file
        // behind, which `media:prune-orphans` reaps (managed layout) — the
        // inverse of the old damage is a stranded file, not a broken image.
        //
        // The row delete is a single atomic statement (`mandants.id` cascades
        // into `event_types`, `badge_images`, `mandant_domains`, …), so either
        // every row goes or none does. What is NOT atomic is the file phase
        // that follows: a process death between the two leaves unreferenced
        // files, which is exactly the residual this order accepts in exchange
        // for never publishing a dangling reference.
        $this->deleteRowsThenFiles($mandant);

        return response()->noContent();
    }

    /**
     * Drop the mandant row, then remove the files its rows referenced.
     *
     * Step 1 loads the references while the rows still exist (afterwards there
     * is nothing left to read them from), step 2 deletes the row, step 3 purges
     * the files with a bounded retry.
     */
    private function deleteRowsThenFiles(Mandant $mandant): void
    {
        // Snapshot phase — only the two media references and the child models
        // the service purges need. `$mandant` itself keeps its attributes in
        // memory, so `MandantMediaService::purge()` can still read `logo_path`,
        // `header_path` and `slug` after the row is gone.
        $eventTypes = $mandant->eventTypes()->get();
        $badgeImages = BadgeImage::query()->where('mandant_id', $mandant->id)->get();

        DB::transaction(static function () use ($mandant): void {
            $mandant->delete();
        });

        $purges = [
            'mandant logo' => fn () => $this->media->purge($mandant, 'logo'),
            'mandant header' => fn () => $this->media->purge($mandant, 'header'),
        ];

        foreach ($eventTypes as $eventType) {
            $purges[sprintf('event-type#%d logo', $eventType->id)] = fn () => $this->eventTypes->purge($eventType);
        }

        foreach ($badgeImages as $badgeImage) {
            $purges[sprintf('badge-image#%d', $badgeImage->id)] = fn () => $this->badges->destroy($badgeImage);
        }

        $failed = $this->purgeWithRetry($purges);

        if ($failed !== []) {
            Log::error('Deleting a mandant removed its rows but not every media file; the leftovers are unreferenced now.', [
                'mandant_id' => $mandant->id,
                'failed' => array_keys($failed),
            ]);

            // The service's own exception (it names the file and has already
            // logged the removal failure) — surfaced as a 500, like every other
            // failed media removal in the codebase. Note the tenant IS gone: a
            // retry answers 404, and the leftovers are what
            // `media:prune-orphans` collects.
            throw array_values($failed)[0];
        }
    }

    /**
     * Run every purge, retrying a raised one a bounded number of times, and
     * return the ones that never succeeded.
     *
     * The retry is for the transient half of "the file could not be removed"
     * (an NFS hiccup, a briefly read-only mount, an EIO): a purge is idempotent
     * — `MediaStorage::delete()` of an already absent file reports success — so
     * a second attempt is safe. It is BOUNDED on purpose: a file that is truly
     * stuck must not keep a request alive. The delay grows per attempt so a
     * mount that is remounted read-write in between is picked up.
     *
     * A failure does not abort the remaining purges: the row is already gone, so
     * every other file is collectable right now, and stopping at the first stuck
     * file would strand all of them for the reaper as well.
     *
     * @param  array<string, callable(): void>  $purges
     * @return array<string, RuntimeException> label => the last failure
     */
    private function purgeWithRetry(array $purges): array
    {
        $failed = [];

        foreach ($purges as $label => $purge) {
            for ($attempt = 1; ; $attempt++) {
                try {
                    $purge();

                    break;
                } catch (RuntimeException $exception) {
                    if ($attempt >= self::PURGE_ATTEMPTS) {
                        $failed[$label] = $exception;

                        break;
                    }

                    usleep(self::PURGE_RETRY_DELAY_MICROSECONDS * $attempt);
                }
            }
        }

        return $failed;
    }

    /**
     * Validation rules. On update (`forCreate = false`) name/slug become
     * `sometimes` so partial payloads are supported.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(?Mandant $mandant = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [$main, 'string', 'max:255', new ValidUtf8],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('mandants', 'slug')->ignore($mandant?->id),
            ],
            'teams_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'impressum_text' => ['nullable', 'string', new ValidUtf8],
            'privacy_text' => ['nullable', 'string', new ValidUtf8],
            'smtp_config' => ['sometimes', 'nullable', 'array'],
            'smtp_config.host' => ['nullable', 'string', 'max:255', new ValidUtf8],
            'smtp_config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_config.username' => ['nullable', 'string', 'max:255', new ValidUtf8],
            'smtp_config.encryption' => ['nullable', 'string', 'max:50', new ValidUtf8],
            'smtp_config.password' => ['nullable', 'string', 'max:255', new ValidUtf8],
        ];
    }

    /**
     * The explicit "clear SMTP config" state: every key back to null,
     * including the password, so `smtp_has_password` flips to false.
     *
     * @return array<string, null>
     */
    private function clearedSmtpConfig(): array
    {
        return [
            'host' => null,
            'port' => null,
            'username' => null,
            'password' => null,
            'encryption' => null,
        ];
    }
}
