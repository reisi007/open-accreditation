<?php

namespace App\Http\Resources;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\RoleUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Event detail for the public portal (P3a): the calendar fields plus the
 * effective venue/deadline and the event manager contact. Contact resolution:
 * team events → first team_admin of the team; mandant-level events → first
 * mandant_admin of the mandant; none found → null.
 *
 * W12: `venue_effective` is the resolved venue NAME — the event's own
 * `venue_id`, else the `venue_id` of its team. Both are references into the
 * mandant's venue master data; a deactivated venue still resolves to its name
 * (deactivation stops new assignments, it does not erase history).
 */
class PortalEventDetailResource extends PortalEventResource
{
    public function __construct(mixed $resource, private readonly Mandant $mandant)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'venue_effective' => $this->venue?->name ?? $this->team?->venue?->name,
            'deadline_effective' => ($this->deadline_end ?? $this->deadline_start)?->format('Y-m-d'),
            'contact' => $this->contact(),
        ];
    }

    /**
     * @return array{name: string, email: string}|null
     */
    private function contact(): ?array
    {
        $query = RoleUser::query()->forMandant($this->mandant->id);

        if ($this->team_id !== null) {
            $query->forTeam($this->team_id)->whereHas(
                'role',
                fn (Builder $q) => $q->where('roles.slug', UserRole::TEAM_ADMIN->value),
            );
        } else {
            // mandant_admin assignments carry no team scope (enforced by the
            // admin role validation), so the pivot team_id is null.
            $query->forTeam(null)->whereHas(
                'role',
                fn (Builder $q) => $q->where('roles.slug', UserRole::MANDANT_ADMIN->value),
            );
        }

        $assignment = $query->orderBy('role_user.id')->with('user')->first();

        if ($assignment === null || $assignment->user === null) {
            return null;
        }

        return [
            'name' => $assignment->user->name,
            'email' => $assignment->user->email,
        ];
    }
}
