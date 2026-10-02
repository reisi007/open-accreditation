<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendMandantMail;
use App\Mail\ActivationMail;
use App\Mail\ApplicationApprovedMail;
use App\Mail\PassMail;
use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\AllocationService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Position 45 (2026-10-02): EVERY mandant mail goes through the queue.
 *
 * These tests assert the ENQUEUE, not the delivery: `Queue::fake()` intercepts
 * `SendMandantMail::dispatch`, so the job identity (its mailable class and
 * recipient) is observable without a transport. Delivery, retry and
 * dead-lettering are covered by `MailDeadLetterTest`; the `after_commit` timing
 * by `QueuedMailAfterCommitTest`.
 */
class QueuedMailTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->mandant = Mandant::factory()->create(['slug' => 'verband-a', 'name' => 'Verband A']);
        $this->mandant->domains()->create(['hostname' => 'verband-a.test']);

        MandantContext::set($this->mandant);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    /**
     * The registration path used to send inline INSIDE its transaction and to
     * throw on relay failure. It now enqueues: the answer stays 201 whether or
     * not the mail is out yet (Nutzerentscheid 1).
     */
    public function test_registration_enqueues_the_activation_mail_and_still_answers_201(): void
    {
        Queue::fake();

        $this->postJson('/api/auth/register', [
            'name' => 'Max Mustermann',
            'email' => 'max@example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Registrierung erfolgreich. Bitte prüfe deine E-Mail zur Aktivierung.');

        Queue::assertPushed(SendMandantMail::class, function (SendMandantMail $job): bool {
            return $job->mandantId === $this->mandant->id
                && $job->mailableClass === ActivationMail::class
                && $job->recipient === 'max@example.com';
        });
    }

    public function test_allocation_approval_enqueues_the_mail(): void
    {
        Queue::fake();

        $application = $this->request($this->createAccreditation(), User::factory()->create());

        app(AllocationService::class)->approveApplication($application);

        Queue::assertPushed(SendMandantMail::class, function (SendMandantMail $job): bool {
            return $job->mailableClass === ApplicationApprovedMail::class
                && $job->recipient !== null;
        });
    }

    public function test_admin_resend_enqueues_again_instead_of_sending_inline(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $application = $this->request($this->createAccreditation(), $user, ['status' => 'approved']);

        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/applications/'.$application->id.'/resend')
            ->assertOk()
            ->assertJsonPath('message', 'E-Mail wurde erneut gesendet.');

        Queue::assertPushed(SendMandantMail::class, function (SendMandantMail $job) use ($user): bool {
            return $job->mailableClass === PassMail::class
                && $job->recipient === $user->email;
        });
    }

    /* ---------------------------------------------------------------------
     | Helpers (mirroring MailTest)
     | ------------------------------------------------------------------- */

    private static int $categorySeq = 0;

    private function createAccreditation(array $attributes = []): Accreditation
    {
        $category = $this->mandant->categories()->create([
            'name' => 'Presse',
            'slug' => 'presse-'.(++self::$categorySeq),
        ]);

        return $this->mandant->accreditations()->create([
            'category_id' => $category->id,
            'scope' => 'season',
            'quota' => 5,
            ...$attributes,
        ]);
    }

    private function request(Accreditation $accreditation, User $user, array $attributes = []): Application
    {
        return Application::create([
            'accreditation_id' => $accreditation->id,
            'user_id' => $user->id,
            'status' => 'requested',
            'priority' => false,
            ...$attributes,
        ]);
    }

    private function superAdmin(): User
    {
        return $this->createUserWithRole(UserRole::SUPER_ADMIN->value, null);
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
