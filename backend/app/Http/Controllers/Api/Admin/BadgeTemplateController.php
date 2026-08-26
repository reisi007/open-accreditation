<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\BadgeTemplateResource;
use App\Models\BadgeTemplate;
use App\Models\RoleUser;
use App\Services\BadgeRenderService;
use App\Services\BadgeTemplateService;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
 * template of a foreign mandant is 404.
 *
 * `layout` follows schema v2 (see features/badge-template-editor.md) and stays
 * backwards compatible: every entry carries `field` + absolute coordinates
 * (`x/y/w/h` in mm on the A6 card), text fields additionally `size` (pt) +
 * `align`. The dedicated `qr` entry positions the verification QR and — like
 * the freely placed `image` entry (mandant brand media or uploaded badge
 * image) — may omit `size`/`align` (both are meaningless for them); at most
 * one `qr` per template; without one the renderer falls back to the historical
 * fixed bottom-right position. Bounds derive from the renderer's A6 constants
 * (no duplicated magic numbers). The `image` source is a strict union:
 * `{kind: brand, ref: logo|header}` or `{kind: upload, image_id: int}` — never
 * client-controlled paths/URLs. NOTE: existence + mandant-scoping checks for
 * `upload.image_id` land together with the `badge_images` infrastructure slice;
 * until then only the union structure is validated here. `is_default` follows
 * the one-default-per-mandant rule via `BadgeTemplateService`.
 */
class BadgeTemplateController extends Controller
{
    use ResolvesAdminTeamScope;

    /**
     * Layout schema v2 field whitelist: the six historical data fields plus
     * `team` (accreditation team name) and `vest_number` (user's Westennummer).
     */
    private const DATA_FIELDS = ['name', 'category', 'event', 'date', 'photo', 'status', 'team', 'vest_number'];

    /**
     * `qr` is its own entry type (not a data field): it positions the
     * verification QR and must appear at most once per template. `image` is
     * the freely placed picture entry (brand ref or upload id as `src`); both
     * may omit the meaningless `size`/`align`.
     */
    private const LAYOUT_FIELDS = [...self::DATA_FIELDS, 'qr', 'image'];

    /** Valid refs for an `image` entry with a `{kind: brand}` source. */
    private const IMAGE_BRAND_REFS = ['logo', 'header'];

    /** Valid values of the optional `image` object-fit switch. */
    private const IMAGE_FITS = ['contain', 'cover'];

    /**
     * Minimum box sizes in mm (spec: text fields 5 × 3, photo/qr 10 × 10 —
     * a smaller box would render invisible or unusable).
     */
    private const MIN_TEXT_W_MM = 5.0;

    private const MIN_TEXT_H_MM = 3.0;

    private const MIN_BOX_W_MM = 10.0;

    private const MIN_BOX_H_MM = 10.0;

    /** Font size range in pt. */
    private const MIN_FONT_SIZE_PT = 1;

    private const MAX_FONT_SIZE_PT = 72;

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

        $validated = $this->validateTemplate($request);

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

        $validated = $this->validateTemplate($request);

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
     * Scalar rules per layout entry. Cross-field semantics (A6 bounds,
     * minimum sizes, conditional size/align requirement, qr uniqueness) are
     * enforced by `validateTemplate()` so failures land on exact leaf keys.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'layout' => ['required', 'array', 'min:1'],
            'layout.*.field' => ['required', Rule::in(self::LAYOUT_FIELDS)],
            'layout.*.x' => ['required', 'numeric', 'min:0'],
            'layout.*.y' => ['required', 'numeric', 'min:0'],
            'layout.*.w' => ['required', 'numeric', 'min:0'],
            'layout.*.h' => ['required', 'numeric', 'min:0'],
            // `size`/`align` stay optional at the scalar layer only for the
            // qr/image entries (they are meaningless there); data fields
            // require both (historical contract) — enforced in
            // `validateLayoutGeometry`.
            'layout.*.size' => ['nullable', 'integer', 'min:'.self::MIN_FONT_SIZE_PT, 'max:'.self::MAX_FONT_SIZE_PT],
            'layout.*.align' => ['nullable', Rule::in(['left', 'center', 'right'])],
            'layout.*.src' => ['nullable', 'array'],
            'layout.*.fit' => ['nullable', Rule::in(self::IMAGE_FITS)],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validate the request and the schema-v2 layout invariants
     * (server-authoritative, features/badge-template-editor.md):
     *
     * - A6 bounds: `x + w ≤ width` and `y + h ≤ height` (constants from the
     *   render service — no duplicated magic-number pair),
     * - minimum box sizes (text 5 × 3 mm, photo/qr/image 10 × 10 mm),
     * - data fields carry `size` + `align` (historical contract), the `qr`
     *   and `image` entries may omit both,
     * - at most one `qr` entry; omitting it selects the renderer's fixed
     *   fallback position,
     * - every `image` entry carries a valid source union (`brand`+ref or
     *   `upload`+integer id).
     *
     * Failures are reported on exact leaf keys (`layout.<i>.<key>`) so clients
     * can highlight the offending input.
     *
     * @return array{name: string, layout: list<array<string, mixed>>, is_default?: bool}
     */
    private function validateTemplate(Request $request): array
    {
        $validated = $request->validate($this->rules());

        $this->validateLayoutGeometry((array) $validated['layout']);

        return $validated;
    }

    /**
     * @param  array<int, mixed>  $layout
     */
    private function validateLayoutGeometry(array $layout): void
    {
        $errors = [];
        $firstQrIndex = null;

        foreach ($layout as $index => $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $at = fn (string $key): string => 'layout.'.$index.'.'.$key;
            $field = $entry['field'] ?? null;
            $isBox = $field === 'qr' || $field === 'photo' || $field === 'image';

            // Data fields always carry font size + alignment (historical
            // contract); the qr/image entries may omit them (ignored by the
            // renderer).
            if ($field !== 'qr' && $field !== 'image') {
                if (! isset($entry['size'])) {
                    $errors[$at('size')] = 'Data fields require a font size.';
                }

                if (! isset($entry['align'])) {
                    $errors[$at('align')] = 'Data fields require an alignment.';
                }
            }

            $x = (float) ($entry['x'] ?? 0);
            $y = (float) ($entry['y'] ?? 0);
            $w = (float) ($entry['w'] ?? 0);
            $h = (float) ($entry['h'] ?? 0);

            // A6 portrait bounds: the box must stay on the card.
            if ($x + $w > BadgeRenderService::A6_WIDTH_MM) {
                $errors[$at('w')] = 'The field extends beyond the right card edge.';
            }

            if ($y + $h > BadgeRenderService::A6_HEIGHT_MM) {
                $errors[$at('h')] = 'The field extends beyond the bottom card edge.';
            }

            // Minimum sizes instead of the historical "≥ 0" (an invisible
            // sub-minimum box is rejected outright).
            $minW = $isBox ? self::MIN_BOX_W_MM : self::MIN_TEXT_W_MM;
            $minH = $isBox ? self::MIN_BOX_H_MM : self::MIN_TEXT_H_MM;

            if ($w < $minW) {
                $errors[$at('w')] = sprintf('Minimum width is %s mm.', $minW);
            }

            if ($h < $minH) {
                $errors[$at('h')] = sprintf('Minimum height is %s mm.', $minH);
            }

            // At most one qr entry per template.
            if ($field === 'qr') {
                if ($firstQrIndex !== null) {
                    $errors[$at('field')] = 'Only one qr field is allowed per template.';
                }

                $firstQrIndex ??= $index;
            }

            // The image entry requires a valid source union — either the
            // mandant's brand media (`{kind: brand, ref: logo|header}`) or an
            // uploaded badge image (`{kind: upload, image_id: <int>}`).
            // Client-controlled paths/URLs are never accepted (SSRF/path
            // traversal/cross-mandant leak protection). Existence + mandant
            // scoping of `image_id` land with the badge_images infrastructure
            // slice; the union structure is enforced here already.
            if ($field === 'image') {
                $srcError = $this->imageSourceError($entry['src'] ?? null);
                if ($srcError !== null) {
                    $errors[$at('src')] = $srcError;
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The validation error of one `image` entry's `src` union, or null when
     * the source is structurally valid.
     *
     * @param  mixed  $src  the raw `src` value of an image entry
     */
    private function imageSourceError(mixed $src): ?string
    {
        if (! is_array($src)) {
            return 'The image field requires a source.';
        }

        $kind = $src['kind'] ?? null;

        if ($kind === 'brand') {
            return in_array($src['ref'] ?? null, self::IMAGE_BRAND_REFS, true)
                ? null
                : 'The brand source must reference logo or header.';
        }

        if ($kind === 'upload') {
            $imageId = $src['image_id'] ?? null;

            return is_int($imageId) && $imageId >= 1
                ? null
                : 'The upload source must carry a positive integer image id.';
        }

        return 'The image source kind must be brand or upload.';
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
