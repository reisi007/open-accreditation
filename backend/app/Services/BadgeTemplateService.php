<?php

namespace App\Services;

use App\Models\BadgeTemplate;

/**
 * Badge template business rules. The only rule so far is the "one default per
 * mandant" invariant (P4): making a template the default un-defaults the
 * previous default of the same mandant. Enforced here — deliberately not as a
 * DB constraint, keeping the schema portable (a partial unique index would be
 * Postgres-specific).
 */
final class BadgeTemplateService
{
    public function setAsDefault(BadgeTemplate $template): BadgeTemplate
    {
        BadgeTemplate::query()
            ->forMandant($template->mandant_id)
            ->whereKeyNot($template->id)
            ->where('is_default', true)
            ->update(['is_default' => false, 'updated_at' => now()]);

        if (! $template->is_default) {
            $template->update(['is_default' => true]);
        }

        return $template->fresh();
    }
}
