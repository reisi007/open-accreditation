<?php

namespace Tests\Feature;

use App\Console\Commands\MediaConvertToWebpCommand;
use App\Console\Commands\MediaPruneOrphansCommand;
use App\Console\Commands\SendReminders;
use App\Mail\DeadlineReminderMail;
use App\Models\Accreditation;
use App\Models\Category;
use App\Models\Mandant;
use App\Services\MediaPathService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * WP-10-a — the three batch commands must stream, not materialise.
 *
 * `media:prune-orphans` (weekly), `media:convert-to-webp` (one-shot backfill)
 * and `reminders:send` (daily) all run system-wide, i.e. across every mandant
 * and not just the current one. All three read their candidate tables with
 * `->get()`, so one run hydrated every mandant, team, event type, badge image,
 * accreditation and application of the installation at once — the prune command
 * additionally buffered the complete media tree via `allFiles()`.
 *
 * An end-result assertion cannot catch that: the same files are converted,
 * pruned or mailed either way. These tests therefore count the statements —
 * a set larger than the command's chunk size must be read in more than one
 * round trip — and additionally check that the tail of the last batch is really
 * processed.
 */
class ConsoleCommandChunkingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rows per `insert()` statement. SQLite caps the bound parameters of one
     * statement (999 on older builds), and a 200-row fixture with eight
     * columns would sit right on that edge.
     */
    private const INSERT_BATCH = 100;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | media:prune-orphans
     | ------------------------------------------------------------------- */

    public function test_prune_orphans_reads_every_media_table_in_more_than_one_batch(): void
    {
        $this->seedMediaTables($this->rowsFor(MediaPruneOrphansCommand::class, 'DB_CHUNK_SIZE'));

        $queries = $this->recordQueries(fn () => Artisan::call('media:prune-orphans'));

        foreach (['mandants', 'teams', 'event_types', 'badge_images'] as $table) {
            $this->assertGreaterThanOrEqual(
                2,
                $this->countSelectsFrom($queries, $table),
                sprintf('`media:prune-orphans` read `%s` in a single statement — the chunking is gone.', $table),
            );
        }
    }

    /**
     * The walk itself must not buffer the tree either: `allFiles()` is exactly
     * the lazy listing plus an accumulator, so a command that still calls it is
     * caught here by the forbidden method instead of silently regressing.
     */
    public function test_prune_orphans_walks_the_tree_lazily_instead_of_buffering_it(): void
    {
        $media = Storage::disk(MediaPathService::DISK);
        $private = Storage::disk('private');

        $media->put('verband-a.test/teams/gone/logo.png', 'orphan-bytes');

        $lazyOnly = Mockery::mock(Filesystem::class);
        $lazyOnly->shouldNotReceive('allFiles');
        $lazyOnly->shouldReceive('getDriver')->andReturnUsing(fn () => $media->getDriver());
        $lazyOnly->shouldReceive('exists')->andReturnUsing(fn (string $path): bool => $media->exists($path));
        $lazyOnly->shouldReceive('delete')->andReturnUsing(fn (...$paths) => $media->delete(...$paths));

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === MediaPathService::DISK ? $lazyOnly : $private,
        );

        $exitCode = Artisan::call('media:prune-orphans', ['--force' => true]);

        $this->assertSame(0, $exitCode);
        $media->assertMissing('verband-a.test/teams/gone/logo.png');
    }

    /* ---------------------------------------------------------------------
     | media:convert-to-webp
     | ------------------------------------------------------------------- */

    public function test_convert_to_webp_reads_every_media_table_in_more_than_one_batch(): void
    {
        $rows = $this->rowsFor(MediaConvertToWebpCommand::class, 'CHUNK_SIZE');

        $this->seedMediaTables($rows);
        $this->giveEveryMandantARasterLogo();

        $queries = $this->recordQueries(fn () => Artisan::call('media:convert-to-webp'));
        $output = Artisan::output();

        foreach (['mandants', 'teams', 'event_types', 'badge_images'] as $table) {
            $this->assertGreaterThanOrEqual(
                2,
                $this->countSelectsFrom($queries, $table),
                sprintf('`media:convert-to-webp` read `%s` in a single statement — the chunking is gone.', $table),
            );
        }

        // The tail of the last batch is really processed — a `->get()`-shaped
        // implementation that dropped the remainder would stop here.
        $this->assertStringContainsString(
            sprintf('badge-image#%d', (int) DB::table('badge_images')->max('id')),
            $output,
        );
        $this->assertStringContainsString(
            sprintf('mandant#%d logo', (int) DB::table('mandants')->max('id')),
            $output,
        );
    }

    /* ---------------------------------------------------------------------
     | reminders:send
     | ------------------------------------------------------------------- */

    public function test_reminders_read_the_accreditations_in_the_window_in_more_than_one_batch(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        Mail::fake();

        $rows = $this->rowsFor(SendReminders::class, 'ACCREDITATION_CHUNK_SIZE');
        $accreditations = $this->seedAccreditationsInWindow($rows);
        $this->seedOneApplicationPer($accreditations);

        $queries = $this->recordQueries(fn () => Artisan::call('reminders:send'));

        $this->assertGreaterThanOrEqual(
            2,
            $this->countSelectsFrom($queries, 'accreditations'),
            '`reminders:send` read the accreditations in a single statement — the chunking is gone.',
        );

        // Every accreditation is really walked, including the single row that
        // makes up the last (remainder) batch.
        Mail::assertSent(DeadlineReminderMail::class, $rows);
        $this->assertStringContainsString(
            sprintf('Reminder run finished (%d mail(s) sent)', $rows),
            Artisan::output(),
        );
    }

    public function test_reminders_read_the_applications_of_one_accreditation_in_more_than_one_batch(): void
    {
        Carbon::setTestNow('2026-08-14 10:00:00');
        Mail::fake();

        $rows = $this->rowsFor(SendReminders::class, 'APPLICATION_CHUNK_SIZE');
        $accreditation = $this->seedAccreditationsInWindow(1)->first();
        $this->seedApplications($accreditation, $rows);

        $queries = $this->recordQueries(fn () => Artisan::call('reminders:send'));

        $this->assertGreaterThanOrEqual(
            2,
            $this->countSelectsFrom($queries, 'applications'),
            '`reminders:send` read the applications of one accreditation in a single statement — the chunking is gone.',
        );

        // Every application of the last batch is really mailed.
        Mail::assertSent(DeadlineReminderMail::class, $rows);
    }

    /* ---------------------------------------------------------------------
     | The counter the assertions above rely on
     | ------------------------------------------------------------------- */

    /**
     * Control for the `>= 2` thresholds: the shape the commands had before
     * WP-10-a — one `->get()` per table — is exactly one statement, so those
     * assertions really do fail on it. Without this, "more than one round trip"
     * would be a claim about the counter as much as about the commands.
     */
    public function test_the_statement_counter_separates_one_unbatched_read_from_several(): void
    {
        $this->seedMediaTables(3);

        $unbatched = $this->recordQueries(fn () => Mandant::query()->get());
        $chunked = $this->recordQueries(
            fn () => Mandant::query()->chunkById(2, fn () => null),
        );

        $this->assertSame(1, $this->countSelectsFrom($unbatched, 'mandants'));
        $this->assertGreaterThanOrEqual(2, $this->countSelectsFrom($chunked, 'mandants'));
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * Chunk size + 1 rows: one full batch plus a remainder, so `chunkById` has
     * to ask twice. Read through reflection so raising a command's chunk size
     * scales the fixture instead of silently turning the assertion into a
     * tautology.
     */
    private function rowsFor(string $command, string $constant): int
    {
        $size = (new ReflectionClass($command))->getConstant($constant);

        $this->assertIsInt($size, sprintf('%s::%s must be an int chunk size.', $command, $constant));

        return $size + 1;
    }

    /**
     * @return list<string>
     */
    private function recordQueries(callable $run): array
    {
        $queries = [];

        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $run();

        return $queries;
    }

    /**
     * @param  list<string>  $queries
     */
    private function countSelectsFrom(array $queries, string $table): int
    {
        return count(array_filter(
            $queries,
            fn (string $sql): bool => preg_match('/\bfrom "'.preg_quote($table, '/').'"/i', $sql) === 1,
        ));
    }

    /**
     * `mandants`, `teams`, `event_types` and `badge_images` in one go. The
     * mandant of `setUp()` counts as row #1, so `$count` is the total.
     */
    private function seedMediaTables(int $count): void
    {
        $now = now();
        $mandants = $teams = $eventTypes = $badges = [];

        for ($i = 1; $i < $count; $i++) {
            $mandants[] = ['slug' => 'verband-'.$i, 'name' => 'Verband '.$i, 'created_at' => $now, 'updated_at' => $now];
            $teams[] = ['mandant_id' => $this->mandant->id, 'slug' => 'verein-'.$i, 'name' => 'Verein '.$i, 'created_at' => $now, 'updated_at' => $now];
            $eventTypes[] = ['mandant_id' => $this->mandant->id, 'slug' => 'typ-'.$i, 'name' => 'Typ '.$i, 'created_at' => $now, 'updated_at' => $now];
            $badges[] = [
                'mandant_id' => $this->mandant->id,
                'path' => 'verband-a.test/badges/'.str_pad((string) $i, 26, '0', STR_PAD_LEFT).'.png',
                'mime' => 'image/png',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertInBatches('mandants', $mandants);
        $this->insertInBatches('teams', $teams);
        $this->insertInBatches('event_types', $eventTypes);
        $this->insertInBatches('badge_images', $badges);
    }

    /**
     * Every mandant gets a stored raster logo, so the backfill really has
     * `$count` candidates. The files are deliberately absent: the dry run
     * reports them as skipped and still counts them.
     */
    private function giveEveryMandantARasterLogo(): void
    {
        DB::table('mandants')->update(['logo_path' => 'verband-a.test/logo.png']);
    }

    /**
     * @return Collection<int, Accreditation>
     */
    private function seedAccreditationsInWindow(int $count)
    {
        $category = Category::query()->create([
            'mandant_id' => $this->mandant->id,
            'name' => 'Presse',
            'slug' => 'presse',
        ]);

        $now = now();
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'mandant_id' => $this->mandant->id,
                'category_id' => $category->id,
                'scope' => 'season',
                'quota' => 5,
                'deadline_start' => '2026-08-01',
                'deadline_end' => '2026-08-17',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertInBatches('accreditations', $rows);

        return Accreditation::query()
            ->where('category_id', $category->id)
            ->orderBy('id')
            ->get();
    }

    private function seedApplications(Accreditation $accreditation, int $count): void
    {
        $now = now();
        $users = [];

        for ($i = 1; $i <= $count; $i++) {
            $users[] = [
                'mandant_id' => $this->mandant->id,
                'name' => 'Bewerber '.$i,
                'email' => sprintf('bewerber-%d@example.test', $i),
                'password' => 'irrelevant',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertInBatches('users', $users);

        $userIds = DB::table('users')
            ->where('email', 'like', 'bewerber-%@example.test')
            ->orderBy('id')
            ->pluck('id');

        $this->insertInBatches('applications', $userIds->map(fn ($userId): array => [
            'accreditation_id' => $accreditation->id,
            'user_id' => $userId,
            'status' => 'requested',
            'priority' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * One applicant for each of the given accreditations (a single account may
     * hold one application per accreditation, so one user is enough).
     *
     * @param  Collection<int, Accreditation>  $accreditations
     */
    private function seedOneApplicationPer($accreditations): void
    {
        $now = now();

        DB::table('users')->insert([
            'mandant_id' => $this->mandant->id,
            'name' => 'Bewerber',
            'email' => 'bewerber@example.test',
            'password' => 'irrelevant',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $userId = (int) DB::table('users')->where('email', 'bewerber@example.test')->value('id');

        $this->insertInBatches('applications', $accreditations->map(fn (Accreditation $accreditation): array => [
            'accreditation_id' => $accreditation->id,
            'user_id' => $userId,
            'status' => 'requested',
            'priority' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertInBatches(string $table, array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            if ($chunk !== []) {
                DB::table($table)->insert($chunk);
            }
        }
    }
}
