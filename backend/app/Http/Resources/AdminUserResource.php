<?php

namespace App\Http\Resources;

use App\Models\RoleUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin representation of a mandant user (P2c): identity plus the role
 * assignments within the current mandant. Only the *scoped* pivot rows are
 * loaded (see `UserController::index`) — super_admin global assignments never
 * surface here.
 */
class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'name' => $this->name,
            'roles' => self::rolesPayload(
                $this->relationLoaded('roleUserAssignments') ? $this->roleUserAssignments : [],
            ),
        ];
    }

    /**
     * Shape a collection of role_user assignments into the `roles` payload:
     * one entry per assignment, role as `{slug, name}`, pivot scope
     * (`mandant_id`, `team_id`) and the resolved team (`{id, name}` | null).
     *
     * @param  iterable<RoleUser>  $assignments
     * @return array<int, array<string, mixed>>
     */
    public static function rolesPayload(iterable $assignments): array
    {
        $payload = [];

        foreach ($assignments as $assignment) {
            $payload[] = [
                'role' => [
                    'slug' => $assignment->role->slug,
                    'name' => $assignment->role->name,
                ],
                'mandant_id' => $assignment->mandant_id,
                'team_id' => $assignment->team_id,
                'team' => $assignment->team_id !== null && $assignment->team !== null
                    ? ['id' => $assignment->team->id, 'name' => $assignment->team->name]
                    : null,
            ];
        }

        return $payload;
    }
}
