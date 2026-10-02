<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendMandantMail;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PlainTestMailable;
use Tests\TestCase;

/**
 * Position 45 (2026-10-02): the dead-letter queue for undelivered mandant mails.
 *
 * Covers the three claims that make "no mail should be lost" true rather than
 * aspirational: a capped retry ENDS in `failed_jobs` with its `mandant_id`; the
 * DLQ surface is mandant-ISOLATED (a `mandant_admin` never sees a foreign dead
 * letter); and the manual requeue really moves the mail back onto the queue.
 */
class MailDeadLetterTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandantA->domains()->create(['hostname' => 'verband-a.test']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b', 'name' => 'Verband B']);
        $this->mandantB->domains()->create(['hostname' => 'verband-b.test']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /**
     * End-to-end: a delivery that cannot succeed is retried up to the cap and
     * then dead-lettered, with the owning mandant written by the provider.
     */
    public function test_a_capped_retry_dead_letters_the_mail_with_its_mandant_id(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $mandant = Mandant::factory()->create([
            'smtp_config' => ['host' => '127.0.0.1', 'port' => 1],
        ]);
        MandantContext::set($mandant);

        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to('victim@example.test'));
        $job->tries = 1;
        $job->onConnection('database');
        dispatch($job);

        $this->assertDatabaseCount('jobs', 1);

        Artisan::call('queue:work', ['--once' => true, '--sleep' => 0]);

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 1);
        $this->assertDatabaseHas('failed_jobs', ['mandant_id' => $mandant->id]);
    }

    public function test_a_mandant_admin_sees_only_the_dead_letters_of_his_own_mandant(): void
    {
        $own = $this->insertFailedMail($this->mandantA, 'own@example.test');
        $this->insertFailedMail($this->mandantB, 'foreign@example.test');

        $response = $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame($own, $data[0]['id']);
        $this->assertSame($this->mandantA->id, $data[0]['mandant_id']);
        $this->assertSame('own@example.test', $data[0]['recipient']);
        $this->assertSame(PlainTestMailable::class, $data[0]['mailable']);
    }

    public function test_a_super_admin_sees_the_dead_letters_of_every_mandant(): void
    {
        $this->insertFailedMail($this->mandantA, 'a@example.test');
        $this->insertFailedMail($this->mandantB, 'b@example.test');

        $response = $this->actingAsApi($this->superAdmin())
            ->getJson('/api/admin/failed-mails')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_a_mandant_admin_requeues_his_own_dead_letter(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.after_commit' => false]);

        $id = $this->insertFailedMail($this->mandantA, 'own@example.test');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->postJson('/api/admin/failed-mails/'.$id.'/requeue')
            ->assertOk();

        $this->assertDatabaseMissing('failed_jobs', ['id' => $id]);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_a_mandant_admin_cannot_requeue_a_foreign_dead_letter(): void
    {
        $foreign = $this->insertFailedMail($this->mandantB, 'foreign@example.test');

        $this->actingAsApi($this->mandantAdmin($this->mandantA))
            ->postJson('/api/admin/failed-mails/'.$foreign.'/requeue')
            ->assertStatus(404);

        $this->assertDatabaseHas('failed_jobs', ['id' => $foreign]);
    }

    public function test_a_team_admin_is_denied_at_the_route_gate(): void
    {
        $this->insertFailedMail($this->mandantA, 'own@example.test');

        $teamAdmin = $this->createUserWithRole(UserRole::TEAM_ADMIN->value, $this->mandantA->id);

        $this->actingAsApi($teamAdmin)
            ->getJson('/api/admin/failed-mails')
            ->assertStatus(403);
    }

    /**
     * Nutzerentscheid 4: dead letters are kept UNLIMITED. The only way out is a
     * human; no scheduler entry may prune them (Nutzerentscheid 4 explicitly
     * rules out `queue:prune-failed`).
     */
    public function test_the_scheduler_never_prunes_the_dead_letter_queue(): void
    {
        $commands = collect(app(Schedule::class)->events())
            ->map(static fn ($event): string => (string) ($event->command ?? ''))
            ->implode(' ');

        $this->assertStringNotContainsString('queue:prune-failed', $commands);
        $this->assertStringNotContainsString('queue:forget', $commands);
    }

    /* ---------------------------------------------------------------------
     | Helpers
     | ------------------------------------------------------------------- */

    /**
     * A real dead letter: a `SendMandantMail` payload stored in `failed_jobs`,
     * exactly what the provider writes.
     */
    private function insertFailedMail(Mandant $mandant, string $recipient): int
    {
        $job = new SendMandantMail($mandant->id, (new PlainTestMailable)->to($recipient));
        $uuid = (string) Str::uuid();

        return (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'mandant_id' => $mandant->id,
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => SendMandantMail::class,
                'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
                'maxTries' => 5,
                'data' => [
                    'commandName' => SendMandantMail::class,
                    'command' => serialize($job),
                ],
            ]),
            'exception' => 'TransportException: connection refused',
            'failed_at' => now(),
        ]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
    }

    private function mandantAdmin(Mandant $mandant): User
    {
        return $this->createUserWithRole(UserRole::MANDANT_ADMIN->value, $mandant->id);
    }

    private function createUserWithRole(string $roleSlug, ?int $mandantId, ?int $teamId = null): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', $roleSlug)->firstOrFail()->id,
            'mandant_id' => $mandantId,
            'team_id' => $teamId,
        ]);

        return $user;
    }
}
