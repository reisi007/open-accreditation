<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeTemplateResource;
use App\Models\BadgeTemplate;
use App\Models\RoleUser;
use App\Services\BadgeTemplateService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Badge template CRUD (P4), mandant-scoped.
 *
 *   GET    /api/admin/badge-templates
 *   POST   /api/admin/badge-templates              {name*, layout*, is_default?}
 *   PUT    /api/admin/badge-templates/{id}
 *   DELETE /api/admin/badge-templates/{id}
 *
 * Guarded by `can:accreditations.manage`. super_admin and mandant_admin manage
 * templates; a team_admin may only read the list (every write answers 403 —
 * templates are mandant-level resources, a team has nothing to write). A
 * template of a foreign mandant is 404. `layout` is validated strictly:
 * `field` whitelist, `x/y/w/h` ≥ 0 (mm), `size` > 0 (pt), `align` whitelist.
 * `is_default` follows the one-default-per-mandant rule via
 * `BadgeTemplateService`.
 */
class BadgeTemplateController extends Controller
{
    use ResolvesAdminTeamScope;

    public function __construct(private readonly BadgeTemplateService $templates) {}

    public function index(): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();

        return BadgeTemplateResource::collection(
            BadgeTemplate::query()
                ->forMandant($mandantId)
                ->orderBy('name')
                ->orderBy('id')
                ->get(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $this->assertMayWrite($request);

        $validated = $request->validate($this->rules());

        $template = BadgeTemplate::create([
            'mandant_id' => $mandantId,
            'name' => $validated['name'],
            'layout' => $validated['layout'],
            'is_default' => (bool) ($validated['is_default'] ?? false),
        ]);

        if ($template->is_default) {
            $this->templates->setAsDefault($template);
        }

        return (new BadgeTemplateResource($template->fresh()))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, BadgeTemplate $badgeTemplate): BadgeTemplateResource
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($badgeTemplate, $mandantId);
        $this->assertMayWrite($request);

        $validated = $request->validate($this->rules());

        $badgeTemplate->update([
            'name' => $validated['name'],
            'layout' => $validated['layout'],
            // Omitted `is_default` keeps the current flag (full name/layout
            // replacement, default status preserved).
            'is_default' => (bool) ($validated['is_default'] ?? $badgeTemplate->is_default),
        ]);

        if ($badgeTemplate->is_default) {
            $this->templates->setAsDefault($badgeTemplate);
        }

        return new BadgeTemplateResource($badgeTemplate->fresh());
    }

    public function destroy(Request $request, BadgeTemplate $badgeTemplate): Response
    {
        $mandantId = $this->currentMandantId();
        $this->assertMandantScope($badgeTemplate, $mandantId);
        $this->assertMayWrite($request);

        $badgeTemplate->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'layout' => ['required', 'array', 'min:1'],
            'layout.*.field' => ['required', Rule::in(['name', 'category', 'event', 'date', 'photo', 'status'])],
            'layout.*.x' => ['required', 'numeric', 'min:0'],
            'layout.*.y' => ['required', 'numeric', 'min:0'],
            'layout.*.w' => ['required', 'numeric', 'min:0'],
            'layout.*.h' => ['required', 'numeric', 'min:0'],
            'layout.*.size' => ['required', 'integer', 'min:1'],
            'layout.*.align' => ['required', Rule::in(['left', 'center', 'right'])],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Badge templates are mandant-level: any team_admin assignment in the
     * current mandant turns a write into 403 (read stays allowed).
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

        abort_if($isTeamAdmin, 403, 'Badge templates are managed by the Verband admin.');
    }
}
