<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventTypeResource;
use App\Models\EventType;
use App\Models\RoleUser;
use App\Rules\ValidUtf8;
use App\Services\EventTypeMediaService;
use App\Services\EventTypePresetSchema;
use App\Services\MediaStorage;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin CRUD for event types (W2) of the current mandant.
 *
 *   GET    /api/admin/event-types
 *   POST   /api/admin/event-types              {slug*, name*, presets?, active?}
 *   PUT    /api/admin/event-types/{eventType}
 *   DELETE /api/admin/event-types/{eventType}
 *   GET    /api/admin/event-types/{eventType}/logo
 *   POST   /api/admin/event-types/{eventType}/logo   (multipart `file`)
 *   DELETE /api/admin/event-types/{eventType}/logo
 *
 * Guarded by `can:events.manage` (super_admin, mandant_admin, team_admin).
 * Event types are a mandant-level resource: a team_admin may read the list
 * (useful for filtering events) but every write answers 403
 * (`assertMayWrite`, mirrors BadgeTemplateController). The mandant is always
 * derived from MandantContext (never a request parameter) and the route-model
 * binding is tenant-guarded (`EventType::resolveRouteBindingQuery`), so rows
 * and logos of a foreign mandant are 404.
 *
 * Logo files live on the public `media` disk under the W1 layout
 * (`<host>/event-types/<slug>/logo.<ext>` via
 * `EventTypeMediaService`/`MediaPathService::eventTypeFile`, host-neutral
 * `_tenants/<id>/event-types/<slug>/logo.<ext>` without a domain) and are
 * streamed through the auth-gated delivery route. Upload validation mirrors the
 * brand media: `image`, `mimes:jpeg,png,webp`, `max:2048` KB plus the
 * 2000×2000 px limit; the extension derives from the validated MIME type,
 * never from the client name.
 * The `presets` envelope is validated in two layers: a structural guard
 * (assoc object, bounded depth, scalar leaves, ≤ 16 KB, valid UTF-8) here and
 * the fachliches schema (`EventTypePresetSchema`, `v = 1`) via
 * {@see assertPresetsValid()}.
 */
class EventTypeController extends Controller
{
    use ResolvesAdminTeamScope;

    /**
     * Maximum encoded size of the `presets` JSON envelope (16 KB).
     */
    private const PRESETS_MAX_BYTES = 16384;

    /**
     * Maximum nesting depth of the `presets` envelope (number of array levels
     * below the root; a flat object is depth 0).
     */
    private const PRESETS_MAX_DEPTH = 3;

    public function __construct(
        private readonly EventTypeMediaService $media,
        private readonly MediaStorage $storage,
        private readonly EventTypePresetSchema $presetSchema,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();

        $query = EventType::query()->forMandant($mandantId);

        if (($active = $this->activeFilter($request)) !== null) {
            $query->active($active);
        }

        return EventTypeResource::collection(
            $query->orderBy('name')->orderBy('id')->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $this->assertMayWrite($request);

        $validated = $this->validatePayload($request, $mandantId, forCreate: true);

        $eventType = EventType::create([
            'mandant_id' => $mandantId,
            ...$validated,
        ]);

        return (new EventTypeResource($eventType))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, EventType $eventType): EventTypeResource
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($eventType, $mandantId);
        $this->assertMayWrite($request);

        $validated = $this->validatePayload($request, $mandantId, $eventType);

        $previousSlug = $eventType->slug;

        $eventType->update($validated);

        // W2-F2 L2: a slug change moves the logo file along, so the stored path
        // never points at a directory of the previous slug.
        $this->media->moveForSlugChange($eventType->fresh(), $previousSlug);

        return new EventTypeResource($eventType->fresh());
    }

    public function destroy(Request $request, EventType $eventType): Response
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($eventType, $mandantId);
        $this->assertMayWrite($request);

        // The DB FK does not touch files — drop the logo from the public media
        // disk explicitly before the row disappears.
        $this->media->purge($eventType);

        $eventType->delete();

        return response()->noContent();
    }

    public function showLogo(EventType $eventType): StreamedResponse|JsonResponse
    {
        $this->currentMandantId();
        $this->assertMandantScope($eventType, (int) MandantContext::currentId());

        $path = $eventType->logo_path;

        if ($path === null || ! $this->storage->exists($path)) {
            return response()->json(['message' => 'Kein Bild hinterlegt.'], 404);
        }

        return $this->storage->response($path, null, [
            'Content-Type' => $this->storage->mimeType($path),
        ]);
    }

    public function storeLogo(Request $request, EventType $eventType): EventTypeResource
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($eventType, $mandantId);
        $this->assertMayWrite($request);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->media->store($eventType, $file);

        return new EventTypeResource($eventType->fresh());
    }

    public function destroyLogo(Request $request, EventType $eventType): Response
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($eventType, $mandantId);
        $this->assertMayWrite($request);

        $this->media->destroy($eventType);

        return response()->noContent();
    }

    /**
     * Validate the CRUD payload (slug unique per mandant, structural `presets`
     * envelope).
     *
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, int $mandantId, ?EventType $eventType = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        $validated = $request->validate([
            'name' => [$main, 'string', 'max:255', new ValidUtf8],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('event_types', 'slug')
                    ->where('mandant_id', $mandantId)
                    ->ignore($eventType?->id),
            ],
            'presets' => ['nullable', 'array'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (($validated['presets'] ?? null) !== null) {
            $this->assertPresetsValid((array) $validated['presets'], $mandantId);
        }

        return $validated;
    }

    /**
     * Structural `presets` guard + fachliches schema:
     * the root must be a JSON object (assoc map), the nesting depth is
     * bounded, leaves are scalars and the encoded payload stays ≤ 16 KB.
     * Structural failures land on the `presets` key; schema failures are
     * reported on the exact leaf key (`presets.<path>`) by
     * {@see EventTypePresetSchema}. Both answer 422.
     *
     * @param  array<array-key, mixed>  $presets
     *
     * @throws ValidationException
     */
    private function assertPresetsValid(array $presets, int $mandantId): void
    {
        if ($presets !== [] && array_is_list($presets)) {
            throw ValidationException::withMessages([
                'presets' => 'Presets must be a JSON object.',
            ]);
        }

        if ($this->presetsDepth($presets) > self::PRESETS_MAX_DEPTH) {
            throw ValidationException::withMessages([
                'presets' => sprintf('Presets must not be nested deeper than %d levels.', self::PRESETS_MAX_DEPTH),
            ]);
        }

        if (! $this->onlyScalarLeaves($presets)) {
            throw ValidationException::withMessages([
                'presets' => 'Presets may only contain scalar values.',
            ]);
        }

        try {
            $encoded = json_encode($presets, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'presets' => 'Presets contain invalid characters.',
            ]);
        }

        if (strlen($encoded) > self::PRESETS_MAX_BYTES) {
            throw ValidationException::withMessages([
                'presets' => sprintf('Presets must not exceed %d bytes.', self::PRESETS_MAX_BYTES),
            ]);
        }

        $this->presetSchema->validate($presets, $mandantId);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function presetsDepth(array $value): int
    {
        $depth = 0;

        foreach ($value as $item) {
            if (is_array($item)) {
                $depth = max($depth, 1 + $this->presetsDepth($item));
            }
        }

        return $depth;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function onlyScalarLeaves(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item)) {
                if (! $this->onlyScalarLeaves($item)) {
                    return false;
                }
            } elseif (! is_scalar($item) && $item !== null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Event types are mandant-level: any team_admin assignment in the current
     * mandant turns a write into 403 (read stays allowed). Mirrors
     * `BadgeTemplateController::assertMayWrite`.
     */
    private function assertMayWrite(Request $request): void
    {
        $user = $request->user();
        $mandantId = MandantContext::currentId();

        if ($user === null || $mandantId === null) {
            return;
        }

        $assignments = $user->roleAssignmentsForMandant($mandantId);

        $isTeamAdmin = $assignments->contains(
            static fn (RoleUser $assignment): bool => $assignment->role->slug === UserRole::TEAM_ADMIN->value,
        );

        abort_if($isTeamAdmin, 403, 'Event types are managed by the Verband admin.');
    }

    private function activeFilter(Request $request): ?bool
    {
        if (! $request->has('active')) {
            return null;
        }

        return filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN);
    }
}
