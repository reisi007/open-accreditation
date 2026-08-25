<?php

namespace Tests\Unit;

use App\Models\Event;
use App\Models\Mandant;
use App\Support\LikeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P3e-B3: regression contract for the consolidated LIKE-search helper. The
 * four former controller-local `escapeLike()` duplicates must behave exactly
 * as before: `%`, `_` and `\` masked, applied with an explicit
 * `ESCAPE '\'` clause, and combined with `LOWER()` on both sides so the
 * search stays case-insensitive on Postgres AND SQLite.
 */
class LikeSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_escape_masks_all_like_wildcards(): void
    {
        $this->assertSame('100\% Chance', LikeSearch::escape('100% Chance'));
        $this->assertSame('a\_b', LikeSearch::escape('a_b'));
        $this->assertSame('back\\\\slash', LikeSearch::escape('back\slash'));
        $this->assertSame('plain', LikeSearch::escape('plain'));
        $this->assertSame('', LikeSearch::escape(''));
    }

    public function test_escape_backslash_is_replaced_first_so_it_is_not_double_escaped(): void
    {
        // Every metacharacter must come out escaped exactly once: the input's
        // own backslash doubles, while the escapes inserted for `%`/`_` keep
        // their single leading backslash.
        $this->assertSame('x\%y\_z\\\\', LikeSearch::escape("x%y_z\\"));
    }

    public function test_case_insensitive_like_with_the_controller_clause_finds_rows(): void
    {
        // Mirrors the PortalController usage verbatim (CC-R1): LOWER() on both
        // sides pins case-insensitive search across Postgres (LIKE is
        // case-sensitive) and SQLite (case-insensitive by default).
        $mandant = Mandant::factory()->create();
        $mandant->events()->create(['title' => 'Pokal', 'competition' => 'Pokal Finale']);
        $mandant->events()->create(['title' => 'Liga', 'competition' => 'Liga']);

        foreach (['okal', 'OKAL'] as $needle) {
            $term = LikeSearch::escape($needle);

            $titles = Event::query()
                ->whereRaw("LOWER(competition) like LOWER(?) escape '\\'", ["%{$term}%"])
                ->pluck('title');

            $this->assertSame(['Pokal'], $titles->all(), "search={$needle} must match case-insensitively");
        }
    }

    public function test_escaped_percent_matches_only_literal_percent_signs(): void
    {
        $mandant = Mandant::factory()->create();
        $mandant->events()->create(['title' => 'Prozent', 'competition' => '100% Chance']);
        $mandant->events()->create(['title' => 'Ohne', 'competition' => '100 Chance']);

        $term = LikeSearch::escape('%');

        $titles = Event::query()
            ->whereRaw("LOWER(competition) like LOWER(?) escape '\\'", ["%{$term}%"])
            ->pluck('title');

        $this->assertSame(['Prozent'], $titles->all());
    }
}
