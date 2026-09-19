<?php

namespace App\Models;

use App\Support\MandantContext;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An event participant (W4). Cardinality-free: an event may have one row
 * (Single), two rows (Versus, the default pairing) or N rows (tournament
 * groups sharing a time slot). A row may reference a `team` and/or carry a
 * free-text `name`; `logo_path` optionally carries a non-team image (reserved
 * for the W6 media service — team participants use the team logo).
 *
 * The row has no own `mandant_id`; it belongs to the mandant through its
 * parent `event`, so the route-model binding is scoped via `event.mandant_id`
 * (a foreign tenant's participant is 404). The controller additionally checks
 * that the participant belongs to the event in the URL, so a participant of a
 * sibling event is also 404.
 */
#[Fillable(['event_id', 'team_id', 'name', 'logo_path', 'sort_order'])]
class EventParticipant extends Model
{
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * The display name: an explicit `name` wins, otherwise the referenced
     * team's name, otherwise null (placeholder without a label).
     */
    public function nameEffective(): ?string
    {
        if ($this->name !== null && $this->name !== '') {
            return $this->name;
        }

        return $this->team?->name;
    }

    /**
     * Route-model-binding safety net: a bound participant is only resolved when
     * its parent event belongs to the current mandant (host-derived). Without a
     * resolved mandant (seeders, console commands, tests) the binding stays
     * unscoped. Mirrors the other tenant-guarded bindings at the route
     * boundary.
     */
    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        $query = parent::resolveRouteBindingQuery($query, $value, $field);

        if (MandantContext::hasCurrent()) {
            $mandantId = MandantContext::currentId();

            $query->whereHas('event', static function ($eventQuery) use ($mandantId): void {
                $eventQuery->where('mandant_id', $mandantId);
            });
        }

        return $query;
    }
}
