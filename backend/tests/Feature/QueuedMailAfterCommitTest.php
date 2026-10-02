<?php

namespace Tests\Feature;

use App\Mail\ActivationMail;
use App\Models\Mandant;
use App\Services\MandantMailerService;
use App\Support\MandantContext;
use App\Support\QueuedMailPayload;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;

/**
 * Position 45 (2026-10-02): `after_commit` — "status and delivery are one
 * transaction".
 *
 * ## Why this is observable at all under `RefreshDatabase`
 *
 * `RefreshDatabase` wraps every test in a transaction that never commits, which
 * looks like it would hide the guarantee forever (the callback could never
 * fire). Laravel's TEST transactions manager deliberately ignores that
 * wrapper: `Illuminate\Foundation\Testing\DatabaseTransactionsManager` overrides
 * `callbackApplicableTransactions()` with `skip(count($connectionsTransacting))`
 * and `afterCommitCallbacksShouldBeExecuted()` with `return $level === 1`.
 * So a *nested* `DB::transaction()` (level 2 → 1) really does fire the
 * after-commit callbacks, exactly as production does when the transaction
 * commits. That is what these tests assert, and the last one flips
 * `after_commit` off to show the flag — not the harness — is the cause.
 *
 * ## And the flag itself is pinned
 *
 * The mechanism is proven in both directions above, but every one of those
 * tests SETS the flag first, so none of them fails if `config/queue.php` ever
 * ships `false`. The first test below therefore reads the shipped value without
 * touching it — that is the only assertion here that speaks about production.
 */
class QueuedMailAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /**
     * THE value that ships. Every other test in this class sets the flag
     * itself, so none of them would notice `config/queue.php` being flipped to
     * `false` — and the guarantee ("a rolled-back approval sends nothing") is
     * documented in `features/mail-delivery.md` §2, in four docblocks and as
     * "THE one line" in the config comment.
     *
     * MUTATION: `'after_commit' => false` in `config/queue.php` fails exactly
     * this test and nothing else in the suite.
     */
    public function test_the_shipped_configuration_pins_after_commit(): void
    {
        $this->assertTrue(
            config('queue.connections.database.after_commit'),
            'queue.connections.database.after_commit must ship as true: it is what makes "status and delivery are one transaction" true in production',
        );
    }

    public function test_a_mail_dispatched_inside_a_transaction_is_pushed_only_when_that_transaction_commits(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $mandant = Mandant::factory()->create();

        DB::transaction(function () use ($mandant): void {
            app(MandantMailerService::class)->send($mandant, (new PlainTestMailable)->to('a@example.test'));

            $this->assertDatabaseCount('jobs', 0);
        });

        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_rolled_back_transaction_leaves_no_delivery_order(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $mandant = Mandant::factory()->create();

        try {
            DB::transaction(function () use ($mandant): void {
                app(MandantMailerService::class)->send($mandant, (new PlainTestMailable)->to('a@example.test'));

                throw new RuntimeException('force rollback');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertDatabaseCount('jobs', 0);
    }

    /**
     * The counter-direction: with `after_commit` OFF the order is written
     * immediately, inside the transaction. Without this test the one above could
     * still pass for a harness reason instead of the config reason.
     */
    public function test_without_after_commit_the_order_is_written_inside_the_transaction(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $mandant = Mandant::factory()->create();

        DB::transaction(function () use ($mandant): void {
            app(MandantMailerService::class)->send($mandant, (new PlainTestMailable)->to('a@example.test'));

            $this->assertDatabaseCount('jobs', 1);
        });
    }

    public function test_registration_enqueues_the_activation_mail_on_the_database_connection(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => true]);

        $this->seed(RoleSeeder::class);
        $mandant = Mandant::factory()->create(['slug' => 'verband-a']);
        MandantContext::set($mandant);

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertCreated();

        $this->assertDatabaseCount('jobs', 1);

        $row = DB::table('jobs')->first();
        $mail = QueuedMailPayload::mailJob((string) $row->payload);

        $this->assertNotNull($mail);
        $this->assertSame(ActivationMail::class, $mail->mailableClass);
        $this->assertSame('max@example.com', $mail->recipient);
        $this->assertSame($mandant->id, $mail->mandantId);
    }
}
