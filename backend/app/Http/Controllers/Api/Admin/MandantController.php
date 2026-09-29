<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\MediaRemovalFailedException;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesMandantRouteParameter;
use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\BadgeImage;
use App\Models\EventType;
use App\Models\Mandant;
use App\Rules\ValidUtf8;
use App\Services\BadgeImageService;
use App\Services\EventTypeMediaService;
use App\Services\MandantMediaService;
use App\Services\MediaPurgeRunner;
use App\Support\MandantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin CRUD for mandants (Verbände). Guarded by `can:mandants.manage`
 * (super_admin-only permission — no other role holds it in the matrix).
 *
 * The `{mandant}` route parameter is checked per call against the caller, not
 * against the host, via `assertMandantRouteParameter()`: super_admin addresses
 * any mandant from any host (this is the whole point of the surface), anyone
 * else only the current one. See the trait for why this is a controller check
 * and not a route-binding scope.
 */
class MandantController extends Controller
{
    use ResolvesMandantRouteParameter;

    /**
     * The clause that follows "is unreferenced now and …" in the purge log
     * lines. Every file of THIS cascade lives in the managed layout on the
     * `media` disk, which `media:prune-orphans` really does enumerate — so the
     * promise the log makes is true here. The bounded retry itself
     * (attempts, aggregate budget, "only a removal failure is retried") lives
     * in `MediaPurgeRunner`, shared with the account deletion, because the
     * contract is identical and a second copy would be a second, shorter
     * answer to it.
     */
    private const PURGE_LEFTOVER_ADVICE = '`media:prune-orphans` reaps it';

    public function __construct(
        private readonly MandantMediaService $media,
        private readonly EventTypeMediaService $eventTypes,
        private readonly BadgeImageService $badges,
        private readonly MediaPurgeRunner $purgeRunner,
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

    public function show(Request $request, Mandant $mandant): MandantResource
    {
        $this->assertMandantRouteParameter($request, $mandant);

        return new MandantResource($mandant);
    }

    public function update(Request $request, Mandant $mandant): MandantResource
    {
        $this->assertMandantRouteParameter($request, $mandant);

        $validated = $request->validate($this->rules($mandant));

        // F3: read the stored config BEFORE anything touches the model. On a row
        // written before the `encrypted:json` cast this both yields "nothing to
        // merge into" and takes the unreadable plaintext off the instance, so
        // neither the dirty comparison in `update()` nor the response resource
        // can trip over it again.
        $storedSmtpConfig = $this->takeStoredSmtpConfig($mandant);

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
                $password = $incoming['password'] ?? null;
                unset($incoming['password']);

                if (is_string($password) && $password !== '') {
                    $storedSmtpConfig['password'] = $password;
                }

                $validated['smtp_config'] = array_merge($storedSmtpConfig, $incoming);
            }
        }

        $mandant->update($validated);

        return new MandantResource($mandant);
    }

    public function destroy(Request $request, Mandant $mandant): Response
    {
        $this->assertMandantRouteParameter($request, $mandant);

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
        // for never publishing a dangling reference. The reference snapshot the
        // file phase works from is taken INSIDE that transaction, under a
        // `lockForUpdate()` on the mandant row — see `deleteRowsThenFiles()`.
        $this->deleteRowsThenFiles($mandant);

        return response()->noContent();
    }

    /**
     * Drop the mandant row, then remove the files its rows referenced.
     *
     * The reference snapshot, the row delete and the `lockForUpdate()` all
     * happen inside ONE transaction (F6). The snapshot has to run there and not
     * before: read outside, it saw the rows as they were at an earlier instant,
     * so a concurrent writer could commit a new `logo_path` in between and its
     * file would then be published by nothing — an orphan the delete had
     * promised to collect. The row lock is what makes the snapshot and the
     * delete one atomic decision against other transactions: once this
     * transaction holds the `mandants` row, no other transaction can change the
     * mandant (or, through it, reach the children) until the commit.
     */
    private function deleteRowsThenFiles(Mandant $mandant): void
    {
        /** @var Collection<int, EventType> $eventTypes */
        $eventTypes = new Collection;
        /** @var Collection<int, BadgeImage> $badgeImages */
        $badgeImages = new Collection;

        $deleted = DB::transaction(function () use ($mandant, &$eventTypes, &$badgeImages): ?Mandant {
            // Re-read under the lock: the route-bound instance may be stale by
            // the time this runs, and the snapshot below has to describe the
            // row that is about to go.
            $locked = Mandant::query()->whereKey($mandant->id)->lockForUpdate()->first();

            if ($locked === null) {
                return null;
            }

            $eventTypes = $locked->eventTypes()->get();
            $badgeImages = BadgeImage::query()->where('mandant_id', $locked->id)->get();

            $locked->delete();

            // Handed back so the file phase purges the SAME attributes the
            // snapshot was taken from. The route-bound `$mandant` is only
            // "probably" that row: a concurrent commit between the route
            // binding and this transaction would leave it stale, and purging
            // `logo_path` from a stale instance removes the wrong file while
            // the current one survives as an orphan. The locked instance keeps
            // its attributes in memory after `delete()`.
            return $locked;
        });

        if ($deleted === null) {
            // A concurrent delete won the race. It ran the same cascade — rows
            // and files — so there is nothing left for this request to do.
            return;
        }

        // The mandant row is gone; `$deleted` still carries its attributes in
        // memory, which is why the file phase must not read them from the
        // database any more.
        $purges = [
            'mandant logo' => fn () => $this->media->purge($deleted, 'logo'),
            'mandant header' => fn () => $this->media->purge($deleted, 'header'),
        ];

        foreach ($eventTypes as $eventType) {
            $purges[sprintf('event-type#%d logo', $eventType->id)] = fn () => $this->eventTypes->purge($eventType);
        }

        foreach ($badgeImages as $badgeImage) {
            $purges[sprintf('badge-image#%d', $badgeImage->id)] = fn () => $this->badges->destroy($badgeImage);
        }

        $failed = $this->purgeRunner->run($purges, 'a deleted mandant', self::PURGE_LEFTOVER_ADVICE);

        if ($failed !== []) {
            Log::error('Deleting a mandant removed its rows but not every media file; the leftovers are unreferenced now.', [
                'mandant_id' => $deleted->id,
                'failed' => array_keys($failed),
            ]);

            // F4: the services' own message says "the reference was kept" /
            // "the row was kept" — true for THEIR single-entity path, false
            // here. In this cascade the row is long gone: the mandant delete
            // dropped every row before the first unlink, which is exactly why
            // the leftover is a harmless orphan instead of a dangling
            // reference. An operator reading a 500 for a tenant that no longer
            // exists must not be told its logo reference survived.
            $first = array_values($failed)[0];

            throw new MediaRemovalFailedException(
                $first->path,
                sprintf(
                    'Mandant gelöscht, aber Datei konnte nicht entfernt werden: %s. Die Datei ist unreferenziert und wird vom Reaper aufgeräumt.',
                    $first->path,
                ),
                previous: $first,
            );
        }
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

    /**
     * The stored `smtp_config` to merge an incoming payload into — and, when
     * there is nothing readable, the cleanup that makes the rest of the request
     * survive that fact.
     *
     * F3: `smtp_config` is `encrypted:json` (WP-6-d), so a row written BEFORE
     * that cast holds plain JSON and the encrypter raises `DecryptException` on
     * every read. This runs inside the very remediation
     * `features/02-domain-model.md` documents for such a row ("a re-save: PUT
     * the same `smtp_config`"), and the exception came from THREE places, not
     * one:
     *
     *  1. the merge in `update()` — reading `$mandant->smtp_config`,
     *  2. `$mandant->update()` itself: `getDirty()` casts the ORIGINAL value to
     *     decide whether `smtp_config` changed, which decrypts the plaintext,
     *  3. the `MandantResource` the response serializes, which reads it again.
     *
     * So catching only the merge — the naive fix — still 500s, and the only
     * working call stays `{"smtp_config": null}`, i.e. discarding the
     * credentials. This method therefore ALSO takes the unreadable value off the
     * in-memory instance: with the attribute gone, `getDirty()` has nothing to
     * compare, an incoming `smtp_config` is plainly dirty, and the resource reads
     * the freshly written ciphertext. The database row keeps its plaintext until
     * the operator's payload replaces it (or it is explicitly cleared) — which
     * is the documented per-mandant decision, not a silent data migration.
     *
     * A `DecryptException` therefore means exactly one thing: "legacy plaintext
     * row, replace it entirely". The payload becomes the whole config and is
     * encrypted on save. Consequence, deliberately accepted and documented: the
     * unreadable password is NOT carried over — the API never hands it out
     * (`smtp_has_password` reports presence only), so the operator has to supply
     * it again if it is still needed. The failure is logged because it is the
     * signal that this mandant is one of the rows awaiting remediation.
     *
     * @return array<string, mixed>
     */
    private function takeStoredSmtpConfig(Mandant $mandant): array
    {
        try {
            $config = $mandant->smtp_config;
        } catch (DecryptException) {
            Log::warning('Updating a mandant whose smtp_config predates the encrypted cast: the stored plaintext is replaced by the payload, an unreadable password is not carried over.', [
                'mandant_id' => $mandant->getKey(),
            ]);

            $attributes = $mandant->getAttributes();
            unset($attributes['smtp_config']);
            // `$sync = true` re-baselines `original` onto those attributes, so
            // `getDirty()` finds no ORIGINAL value to decrypt either — that is
            // the second of the two casts the plaintext would have triggered.
            $mandant->setRawAttributes($attributes, true);

            return [];
        }

        return is_array($config) ? $config : [];
    }
}
