<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Admin\Concerns\ResolvesAdminTeamScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for categories (Kategorien) of the current mandant. Guarded by
 * `can:categories.manage` (super_admin, mandant_admin, team_admin).
 *
 * Levels: `team_id = null` → mandant-level, set → team-level. super_admin /
 * mandant_admin manage both levels; team_admin only his own team's team-level
 * categories (mandant-level is read-only for him). A team-level slug may
 * override a mandant-level slug (see `Category::effectiveForTeam()`).
 */
class CategoryController extends Controller
{
    use ResolvesAdminTeamScope;

    public function index(Request $request): AnonymousResourceCollection
    {
        $mandantId = $this->currentMandantId();
        $teamScope = $this->teamScope($request);

        $query = Category::query()
            ->forMandant($mandantId)
            ->with('team');

        if ($teamScope !== null) {
            // team_admin: own team-level categories plus mandant-level ones
            // (read-only). A `team_id` query param is ignored for him.
            $query->where(fn (Builder $q) => $q->whereNull('team_id')->orWhere('team_id', $teamScope));
        } elseif ($request->filled('team_id')) {
            $teamId = (int) $request->input('team_id');
            $this->assertTeamOfMandant($teamId, $mandantId);
            $query->where(fn (Builder $q) => $q->whereNull('team_id')->orWhere('team_id', $teamId));
        }

        return CategoryResource::collection($query->orderBy('name')->orderBy('id')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $mandantId = $this->currentMandantId();
        $teamScope = $this->teamScope($request);

        $validated = $request->validate($this->rules($request, $mandantId, $teamScope, forCreate: true));

        $category = Category::create([
            ...$validated,
            'mandant_id' => $mandantId,
            'team_id' => $this->resolveTeamId($validated, $mandantId, $teamScope),
        ]);

        return (new CategoryResource($category->load('team')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Category $category): CategoryResource
    {
        $mandantId = $this->currentMandantId();
        $category = $this->assertMandantScope($category, $mandantId);
        $teamScope = $this->teamScope($request);
        $this->assertOwnership($category, $teamScope);

        $validated = $request->validate($this->rules($request, $mandantId, $teamScope, $category));

        $category->update([
            ...$validated,
            'team_id' => $this->resolveTeamId($validated, $mandantId, $teamScope, $category),
        ]);

        return new CategoryResource($category->fresh('team'));
    }

    public function destroy(Request $request, Category $category): Response
    {
        $mandantId = $this->currentMandantId();
        $category = $this->assertMandantScope($category, $mandantId);
        $teamScope = $this->teamScope($request);
        $this->assertOwnership($category, $teamScope);

        $category->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(Request $request, int $mandantId, ?int $teamScope, ?Category $category = null, bool $forCreate = false): array
    {
        $main = $forCreate ? 'required' : 'sometimes';

        // The level the written row will live on — decides which unique scope
        // the slug must satisfy.
        $targetTeamId = $teamScope
            ?? ($request->has('team_id') ? $request->input('team_id') : $category?->team_id);

        return [
            'name' => [$main, 'string', 'max:255'],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                $this->slugUniqueRule($mandantId, $targetTeamId, $category),
            ],
            'description' => ['nullable', 'string'],
            'team_id' => ['nullable', 'integer'],
        ];
    }

    private function slugUniqueRule(int $mandantId, mixed $teamId, ?Category $category): Unique
    {
        $levelScope = $teamId === null
            ? static fn ($query) => $query->where('categories.mandant_id', $mandantId)->whereNull('categories.team_id')
            : static fn ($query) => $query->where('categories.mandant_id', $mandantId)->where('categories.team_id', $teamId);

        return Rule::unique('categories', 'slug')->where($levelScope)->ignore($category?->id);
    }
}
