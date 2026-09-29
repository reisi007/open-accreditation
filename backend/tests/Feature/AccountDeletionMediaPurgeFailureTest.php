<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Models\UserMedia;
use App\Services\MediaPurgeRunner;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * The media side of the account deletion: a file that cannot be unlinked is a
 * RESIDUE, not a failed deletion.
 *
 * The account is gone either way — it MUST be gone, that is the whole DSGVO
 * point — so reporting a server error for a leftover file would tell the user
 * his deletion failed when it succeeded, and would tempt a retry of something
 * that is irreversible. The contract is therefore:
 *
 *   unlink fails → log it, continue with the remaining files, name the
 *   leftovers in the response → the account is deleted and the response is 200.
 *
 * Which is the mandant cascade's `purgeWithRetry` contract with ONE deliberate
 * difference in what happens afterwards: the mandant delete re-raises
 * (`MandantController` wants a 500 for a stuck brand file), the account
 * deletion does not. The retry loop itself is shared — `MediaPurgeRunner` —
 * so the bound cannot drift between the two.
 *
 * The unremovable volume is simulated the way `MediaDeleteFailureTest` does it:
 * the affected disk is swapped for a mock whose `delete()` reports failure
 * while `exists()` keeps answering from the real (faked) disk. That is exactly
 * the read-only-volume shape.
 */
class AccountDeletionMediaPurgeFailureTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    /**
     * Paths the disk stub refuses to delete. A property for the same reason as
     * in `MediaDeleteFailureTest`: `Storage::shouldReceive()` reuses an already
     * mocked facade, so a second stub would NOT replace the first.
     *
     * @var list<string>
     */
    private array $unremovablePaths = [];

    /**
     * Invoked for every delete attempt, so a test can count the attempts at
     * exactly the moment the unlink is issued.
     *
     * @var (callable(string): void)|null
     */
    private $onDeleteAttempt = null;

    private FilesystemAdapter $realPrivate;

    private FilesystemAdapter $realMedia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);

        $this->realPrivate = Storage::disk('private');
        $this->realMedia = Storage::disk('media');
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /* ---------------------------------------------------------------------
     | The happy path
     | ------------------------------------------------------------------- */

    public function test_every_private_media_file_of_the_account_is_removed(): void
    {
        $user = $this->member();
        $paths = [
            $this->seedMedia($user, 'portrait', 'a'),
            $this->seedMedia($user, 'press_id', 'b'),
            $this->seedMedia($user, 'attachment', 'c'),
        ];

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.media_files_deleted', 3)
            ->assertJsonPath('data.media_files_left_over', []);

        foreach ($paths as $path) {
            $this->realPrivate->assertMissing($path);
        }

        $this->assertSame(0, UserMedia::query()->count());
    }

    /* ---------------------------------------------------------------------
     | The failure path — the heart of this file
     | ------------------------------------------------------------------- */

    /**
     * A stuck file must not become a 500, and must not stop the OTHER files
     * from being collected either: stopping at the first stuck file would strand
     * all the rest for a manual cleanup too.
     */
    public function test_an_unremovable_file_is_reported_as_residue_while_the_account_is_deleted(): void
    {
        $user = $this->member();

        $stuck = $this->seedMedia($user, 'portrait', 'stuck');
        $removable = $this->seedMedia($user, 'press_id', 'removable');

        $this->unremovablePathsDisk([$stuck]);

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            // The account really is gone — the whole point of a HARD deletion.
            ->assertJsonPath('data.applications_deleted', 0)
            ->assertJsonPath('data.media_files_left_over', [$stuck])
            // One file was really removed even though the other one failed.
            ->assertJsonPath('data.media_files_deleted', 1);

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertSame(0, UserMedia::query()->count());

        // The stuck file survives as an UNREFERENCED orphan — no row points at
        // it any more, which is the whole point of rows-first.
        $this->realPrivate->assertExists($stuck);
        $this->realPrivate->assertMissing($removable);
    }

    /**
     * The log must name the concrete file the moment it is known to be stuck,
     * and it must NOT promise a reaper: `user-media/**` lives on the `private`
     * disk, which `media:prune-orphans` does not enumerate (W5). An operator who
     * is told "the reaper collects it" never cleans it up — so the message names
     * the manual step.
     */
    public function test_a_stuck_file_is_logged_with_the_manual_cleanup_step_not_a_reaper(): void
    {
        $user = $this->member();
        $stuck = $this->seedMedia($user, 'portrait', 'stuck');

        $this->unremovablePathsDisk([$stuck]);

        Log::spy();

        $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

        // Two different `error` lines reach the log for one stuck file: the service's
        // own ("the file is still on the disk") and the runner's summary (the
        // residue advice). The expectation selects the runner's by its context
        // shape (`label` + `attempts`) and only THEN asserts the wording, so a
        // failure names the line that is actually missing instead of tripping
        // over the other one.
        Log::shouldHaveReceived('error')->withArgs(
            function (string $message, array $context) use ($stuck): bool {
                if (($context['path'] ?? null) !== $stuck || ! array_key_exists('attempts', $context)) {
                    return false;
                }

                $this->assertStringContainsString('could not be removed', $message);
                $this->assertStringContainsString('unreferenced now', $message);
                $this->assertStringContainsString('delete it manually', $message);
                // The inverse of the promise is what makes this assertion
                // meaningful: the same sentence must NOT say the reaper does it.
                $this->assertStringNotContainsString('reaps it', $message);

                return true;
            },
        );
    }

    /**
     * The deletion record itself carries the residue, so the leftover can be
     * traced back to the account that no longer exists.
     */
    public function test_the_deletion_log_names_the_residue(): void
    {
        $user = $this->member();
        $stuck = $this->seedMedia($user, 'portrait', 'stuck');

        $this->unremovablePathsDisk([$stuck]);

        Log::spy();

        $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

        Log::shouldHaveReceived('notice')->withArgs(
            fn (string $message, array $context): bool => ($context['media_files_left_over'] ?? null) === [$stuck],
        );
    }

    /* ---------------------------------------------------------------------
     | The bound itself
     | ------------------------------------------------------------------- */

    /**
     * A stuck file is unlinked exactly `MediaPurgeRunner::PURGE_ATTEMPTS` times
     * — the retry must be real (1 would mean there is none), bounded (4+ would
     * mean it is unbounded), and per file rather than once for the whole
     * account.
     *
     * The expected value is read through reflection rather than hard-coded, so a
     * retune of the bound scales this assertion instead of silently keeping it
     * green at whatever the number is. The mandant counterpart
     * (`MediaDeleteFailureTest::test_a_stuck_file_is_unlinked_exactly_purge_attempts_times`)
     * pins the bound to the LITERAL 3 for the same loop, so the two together
     * cannot drift apart silently either.
     */
    public function test_a_stuck_file_is_unlinked_exactly_the_bounded_number_of_times(): void
    {
        $user = $this->member();
        $stuck = $this->seedMedia($user, 'portrait', 'stuck');

        $this->unremovablePathsDisk([$stuck]);

        $attempts = [];
        $this->onDeleteAttempt = function (string $path) use (&$attempts, $stuck): void {
            if ($path === $stuck) {
                $attempts[] = $path;
            }
        };

        $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

        $this->assertSame(MediaPurgeRunner::PURGE_ATTEMPTS, count($attempts));
    }

    /**
     * The counterpart: a removable file is unlinked ONCE. Without this, a
     * runner that blindly retried every purge N times would satisfy the test
     * above as well.
     */
    public function test_a_removable_file_is_unlinked_once(): void
    {
        $user = $this->member();
        $path = $this->seedMedia($user, 'portrait', 'ok');

        $this->unremovablePathsDisk([]);

        $attempts = [];
        $this->onDeleteAttempt = function (string $p) use (&$attempts, $path): void {
            if ($p === $path) {
                $attempts[] = $p;
            }
        };

        $this->actingAsApi($user)->deleteJson('/api/user/account')->assertOk();

        $this->assertSame(1, count($attempts));
    }

    /**
     * Two stuck files must be attempted independently. If the runner aborted
     * after the first failure, the second file would never be touched and would
     * end up as an unlogged, unknown residue.
     */
    public function test_two_stuck_files_are_both_attempted_and_both_reported(): void
    {
        $user = $this->member();
        $first = $this->seedMedia($user, 'portrait', 'first');
        $second = $this->seedMedia($user, 'press_id', 'second');

        $this->unremovablePathsDisk([$first, $second]);

        $attempts = [];
        $this->onDeleteAttempt = function (string $path) use (&$attempts): void {
            $attempts[] = $path;
        };

        $this->actingAsApi($user)
            ->deleteJson('/api/user/account')
            ->assertOk()
            ->assertJsonPath('data.media_files_left_over', [$first, $second]);

        $this->assertCount(2 * MediaPurgeRunner::PURGE_ATTEMPTS, $attempts);

        $this->realPrivate->assertExists($first);
        $this->realPrivate->assertExists($second);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    private function member(): User
    {
        $user = User::factory()->create([
            'mandant_id' => $this->mandant->id,
            'email' => 'loeschbar@example.com',
        ]);

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::USER->value)->firstOrFail()->id,
            'mandant_id' => $this->mandant->id,
            'team_id' => null,
        ]);

        return $user;
    }

    private function seedMedia(User $user, string $type, string $name): string
    {
        $path = sprintf('user-media/%s/%d/%s/%s.jpg', 'verband-a', $user->id, $type, $name);

        $this->realPrivate->put($path, 'bytes');

        UserMedia::create([
            'user_id' => $user->id,
            'type' => $type,
            'path' => $path,
            'mime' => 'image/jpeg',
            'size' => 5,
            'original_name' => $name.'.jpg',
        ]);

        return $path;
    }

    /**
     * Replace the `private` disk with one whose `delete()` fails for exactly the
     * paths in `$this->unremovablePaths` and really removes every other one —
     * the partial-failure shape a read-only bind mount over a single file
     * produces. `exists()` keeps answering from the real (faked) disk, so every
     * "the file is still there" assertion stays honest.
     *
     * @param  list<string>  $paths
     */
    private function unremovablePathsDisk(array $paths): void
    {
        $this->unremovablePaths = $paths;

        $real = $this->realPrivate;
        $other = $this->realMedia;

        $partial = Mockery::mock(Filesystem::class);
        $partial->shouldReceive('delete')->andReturnUsing(
            function ($path) use ($real): bool {
                if (is_string($path) && $this->onDeleteAttempt !== null) {
                    ($this->onDeleteAttempt)($path);
                }

                if (is_string($path) && in_array($path, $this->unremovablePaths, true)) {
                    return false;
                }

                return (array) $real->delete((array) $path) !== [];
            },
        );
        $partial->shouldReceive('exists')->andReturnUsing(
            fn (string $path): bool => $real->exists($path),
        );
        $partial->shouldReceive('put')->andReturnUsing(
            fn (string $path, $contents): bool => $real->put($path, $contents),
        );
        $partial->shouldReceive('get')->andReturnUsing(
            fn (string $path): string => $real->get($path),
        );
        $partial->shouldReceive('getDriver')->andReturnUsing(
            fn () => $real->getDriver(),
        );
        $partial->shouldReceive('putFileAs')->andReturnUsing(
            fn (string $directory, $file, string $name): string|false => $real->putFileAs($directory, $file, $name),
        );

        Storage::shouldReceive('disk')->andReturnUsing(
            fn (string $name): Filesystem => $name === 'private' ? $partial : $other,
        );
    }
}
