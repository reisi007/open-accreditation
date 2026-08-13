<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MandantResource;
use App\Models\Mandant;
use App\Support\MandantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Super Admin CRUD for mandants (Verbände). Guarded by `can:mandants.manage`
 * (super_admin-only permission — no other role holds it in the matrix).
 */
class MandantController extends Controller
{
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

        $mandant->delete();

        return response()->noContent();
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
            'name' => [$main, 'string', 'max:255'],
            'slug' => [
                $main,
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('mandants', 'slug')->ignore($mandant?->id),
            ],
            'teams_enabled' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'impressum_text' => ['nullable', 'string'],
            'privacy_text' => ['nullable', 'string'],
            'smtp_config' => ['sometimes', 'nullable', 'array'],
            'smtp_config.host' => ['nullable', 'string', 'max:255'],
            'smtp_config.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'smtp_config.username' => ['nullable', 'string', 'max:255'],
            'smtp_config.encryption' => ['nullable', 'string', 'max:50'],
            'smtp_config.password' => ['nullable', 'string', 'max:255'],
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
