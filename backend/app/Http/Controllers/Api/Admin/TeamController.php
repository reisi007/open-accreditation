<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\TeamResource;
use App\Models\Mandant;
use App\Models\Team;
use App\Services\MediaPathService;
use App\Support\MandantContext;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Super Admin CRUD for the teams (Vereine) of a mandant.
 *
 * The read endpoint (`index`) is guarded by `can:teams.view` (P2b-F1): a
 * mandant_admin may list all teams of his mandant, a team_admin only his own
 * team(s). Write endpoints stay on `can:teams.manage`, which in the permission
 * matrix is also granted to a team_admin *within his own team scope*. This
 * admin surface manages teams across arbitrary mandants, so every write
 * additionally requires the global super admin role — keeping the tenant-CRUD
 * semantics of this API and closing the cross-mandant manipulation gap.
 *
 * W4 adds the team logo surface (`/api/admin/teams/{team}/logo`): auth-gated
 * delivery (`teams.view`) plus super_admin-only upload/delete, stored under the
 * W1 media layout (`<host>/teams/<slug>/logo.<ext>`).
 */
class TeamController extends Controller
{
    use ResolvesAdminTeamScope;

    /**
     * Maximum width/height for uploaded logos (px), mirroring
     * `MandantMediaService::MAX_IMAGE_DIMENSION`.
     */
    private const MAX_IMAGE_DIMENSION = 2000;

    public function __construct(private readonly MediaPathService $paths) {}

    public function index(Request $request, Mandant $mandant): AnonymousResourceCollection
    {
        $this->authorizeView($request, $mandant);

        $query = $mandant->teams()->orderBy('name');

        $teamIds = $this->teamIds($request);

        if ($teamIds !== []) {
            // team_admin: only his own team(s).
            $query->whereIn('id', $teamIds);
        }

        return TeamResource::collection($query->get());
    }

    public function store(Request $request, Mandant $mandant): JsonResponse
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamsEnabled($mandant);
        $validated = $request->validate($this->rules($mandant, forCreate: true));

        $team = $mandant->teams()->create($validated);

        return (new TeamResource($team))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Mandant $mandant, string $team): TeamResource
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamsEnabled($mandant);
        $teamModel = $mandant->teams()->findOrFail((int) $team);
        $validated = $request->validate($this->rules($mandant, $teamModel));

        $teamModel->update($validated);

        return new TeamResource($teamModel->fresh());
    }

    public function destroy(Request $request, Mandant $mandant, string $team): Response
    {
        $this->authorizeSuperAdmin($request);
        $teamModel = $mandant->teams()->findOrFail((int) $team);
        $teamModel->delete();

        return response()->noContent();
    }

    /**
     * Auth-gated delivery of the team logo (inline). Read access follows
     * `teams.view` (route gate): super_admin, mandant_admin and team_admin may
     * open the image; the team is resolved through the tenant-guarded binding.
     * Writes (`storeLogo`/`destroyLogo`) stay super_admin-only like the rest of
     * this tenant-CRUD surface.
     */
    public function showLogo(Request $request, Team $team): StreamedResponse|JsonResponse
    {
        $this->assertTeamOfCurrentMandant($team);

        $path = $team->logo_path;

        if ($path === null || ! Storage::disk(MediaPathService::DISK)->exists($path)) {
            return response()->json(['message' => 'Kein Bild hinterlegt.'], 404);
        }

        return Storage::disk(MediaPathService::DISK)->response(
            $path,
            null,
            ['Content-Type' => (string) Storage::disk(MediaPathService::DISK)->mimeType($path)],
        );
    }

    /**
     * Upload/replace the team logo. Validation mirrors `EventTypeController` /
     * `MandantMediaService`: `image`, `mimes:jpeg,png,webp`, `max:2048` KB plus
     * the 2000×2000 px dimension limit. The extension derives from the
     * validated MIME type, never from the client filename. The file lands on
     * the public `media` disk under the W1 layout
     * (`<host>/teams/<slug>/logo.<ext>` via `MediaPathService::teamFile`); the
     * previous file is removed only after the new one is stored.
     */
    public function storeLogo(Request $request, Team $team): TeamResource
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamOfCurrentMandant($team);

        $request->validate([
            'file' => ['required', 'image', 'mimes:jpeg,png,webp', 'max:2048'],
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        $this->assertWithinDimensionLimit($file);

        $previous = $team->logo_path;
        $path = $this->logoPath($team, $file);

        Storage::disk(MediaPathService::DISK)->putFileAs(
            dirname($path),
            $file,
            basename($path),
        );

        if ($previous !== null && $previous !== $path) {
            Storage::disk(MediaPathService::DISK)->delete($previous);
        }

        $team->update(['logo_path' => $path]);

        return new TeamResource($team->fresh());
    }

    /**
     * Delete the team logo file and reset `logo_path`.
     */
    public function destroyLogo(Request $request, Team $team): Response
    {
        $this->authorizeSuperAdmin($request);
        $this->assertTeamOfCurrentMandant($team);

        if ($team->logo_path !== null) {
            Storage::disk(MediaPathService::DISK)->delete($team->logo_path);
        }

        $team->update(['logo_path' => null]);

        return response()->noContent();
    }

    /**
     * The relative media path for an uploaded logo. Prefers the mandant's
     * first (primary) domain so the file lands in the documented
     * `<host>/teams/<slug>/` layout. Without a configured domain the path is
     * stored host-neutral (`teams/<slug>/logo.<ext>`) and can be migrated into
     * the domain layout once the mandant has a host (W6 backfill).
     */
    private function logoPath(Team $team, UploadedFile $file): string
    {
        $name = 'logo.'.$this->extensionFor($file);
        $host = $this->mediaHost();

        if ($host === null) {
            return $this->hostNeutralPath($team, $name);
        }

        try {
            return $this->paths->teamFile($host, $team->slug, $name);
        } catch (DomainException) {
            // A legacy/invalid slug or hostname must not turn an upload into a
            // 500 — fall back to the host-neutral path.
            return $this->hostNeutralPath($team, $name);
        }
    }

    /**
     * Host-neutral fallback below the media root (no domain configured yet).
     */
    private function hostNeutralPath(Team $team, string $name): string
    {
        return MediaPathService::TEAMS_SEGMENT
            .'/'.$this->paths->sanitizeSlug($team->slug)
            .'/'.$this->paths->sanitizeFileName($name);
    }

    /**
     * The mandant's first (de-facto primary) domain hostname, or null when no
     * domain is configured. Mirrors `EventTypeController::mediaHost()`.
     */
    private function mediaHost(): ?string
    {
        $host = (MandantContext::current() ?? MandantContext::default())
            ?->domains()
            ->orderBy('id')
            ->value('hostname');

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * File extension derived from the validated MIME type, never from the
     * client-supplied filename (mirrors `MandantMediaService`).
     */
    private function extensionFor(UploadedFile $file): string
    {
        return match (strtolower((string) $file->getMimeType())) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => strtolower((string) $file->getClientOriginalExtension()),
        };
    }

    /**
     * @throws ValidationException
     */
    private function assertWithinDimensionLimit(UploadedFile $file): void
    {
        $dimensions = getimagesize($file->getRealPath());

        if ($dimensions === false) {
            throw ValidationException::withMessages([
                'file' => 'Die Bilddimensionen konnten nicht ermittelt werden.',
            ]);
        }

        [$width, $height] = $dimensions;

        if ($width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION) {
            throw ValidationException::withMessages([
                'file' => sprintf(
                    'Das Bild darf maximal %d×%d Pixel groß sein.',
                    self::MAX_IMAGE_DIMENSION,
                    self::MAX_IMAGE_DIMENSION,
                ),
            ]);
        }
    }

    /**
     * The team must belong to the current mandant context. The route-model
     * binding already scopes to it; this guard also covers the no-context case
     * (where the binding stays unscoped).
     */
    private function assertTeamOfCurrentMandant(Team $team): void
    {
        abort_unless(
            MandantContext::currentId() !== null
                && (int) $team->mandant_id === MandantContext::currentId(),
            404,
        );
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Mandant $mandant, ?Team $team = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        return [
            'name' => [$main, 'string', 'max:255'],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('teams', 'slug')
                    ->where('mandant_id', $mandant->id)
                    ->ignore($team?->id),
            ],
            'home_venue' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function authorizeSuperAdmin(Request $request): void
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
    }

    /**
     * Read access beyond super_admin (P2b-F1: `teams.view` route gate already
     * passed). Non-super admins may only read the teams of *their own* mandant
     * — the URL mandant must equal the current MandantContext, else 404
     * (cross-mandant leak guard).
     */
    private function authorizeView(Request $request, Mandant $mandant): void
    {
        $user = $request->user();

        if ($user?->isSuperAdmin()) {
            return;
        }

        abort_unless((int) $mandant->id === MandantContext::currentId(), 404, 'Team does not belong to the current mandant.');
    }

    /**
     * Teams are an opt-in feature per mandant (`teams_enabled`). Both store
     * and update refuse to touch teams while the feature is disabled.
     */
    private function assertTeamsEnabled(Mandant $mandant): void
    {
        abort_unless((bool) $mandant->teams_enabled, 422, 'Teams are not enabled for this mandant.');
    }
}
