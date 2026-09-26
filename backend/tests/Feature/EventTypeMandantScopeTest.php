<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\Api\Admin\EventTypeController;
use App\Models\EventType;
use App\Models\Mandant;
use App\Models\Role;
use App\Models\RoleUser;
use App\Models\User;
use App\Services\MediaPathService;
use App\Support\MandantContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ReflectionClass;
use Tests\TestCase;

/**
 * WP-6-g: `EventTypeController::store()` merged the validated payload AFTER
 * the host-derived mandant:
 *
 *     EventType::create([
 *         'mandant_id' => $mandantId,   // host-derived
 *         ...$validated,                // spread comes SECOND
 *     ]);
 *
 * Every sibling controller puts the host-derived key AFTER the spread
 * (`CategoryController:67-70`, `EventController:68-71`), so a key present in
 * `$validated` would win — a cross-tenant write.
 *
 * Not exploitable today: `validatePayload()` has no `mandant_id` rule, and
 * `Request::validate()` returns ONLY validated keys, so `$validated` cannot
 * carry the column. `EventType` nevertheless lists `mandant_id` in
 * `#[Fillable]` — one added rule away from a super_admin writing into a
 * foreign mandant. The reorder is defense in depth against exactly that.
 *
 * The behavioural contract ("a `mandant_id` in the payload never reaches the
 * row") is pinned end-to-end by
 * `AdminEventTypeTest::test_client_supplied_mandant_id_is_ignored`. Because
 * validation currently strips the key before it can ever reach the literal,
 * the ORDER itself can only be pinned by reading the source — that is what
 * {@see test_the_host_derived_mandant_id_is_merged_after_the_validated_spread()}
 * is for, and it is the assertion that fails without the fix.
 */
class EventTypeMandantScopeTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);

        Storage::fake(MediaPathService::DISK);

        $this->mandantA = Mandant::factory()->create(['slug' => 'verband-a']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'verband-b']);

        MandantContext::set($this->mandantA);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();

        parent::tearDown();
    }

    public function test_a_mandant_id_in_the_payload_never_reaches_the_row(): void
    {
        $this->actingAsApi($this->superAdmin())
            ->postJson('/api/admin/event-types', [
                'slug' => 'bundesliga',
                'name' => 'Bundesliga',
                'mandant_id' => $this->mandantB->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.mandant_id', $this->mandantA->id);

        $this->assertDatabaseHas('event_types', [
            'mandant_id' => $this->mandantA->id,
            'slug' => 'bundesliga',
        ]);
        $this->assertDatabaseMissing('event_types', ['mandant_id' => $this->mandantB->id]);
    }

    public function test_the_host_derived_mandant_id_is_merged_after_the_validated_spread(): void
    {
        $literal = $this->createLiteralOf(EventTypeController::class, 'store');

        $spreadPosition = strpos($literal, '...$validated');
        $mandantPosition = strpos($literal, "'mandant_id' =>");

        $this->assertIsInt($spreadPosition, 'the store() create() literal must spread $validated');
        $this->assertIsInt($mandantPosition, 'the store() create() literal must set a host-derived mandant_id');
        $this->assertLessThan(
            $mandantPosition,
            $spreadPosition,
            'WP-6-g: `...$validated` must be merged BEFORE the host-derived `mandant_id`, '
            .'otherwise a `mandant_id` key in `$validated` silently wins. Same order as '
            .'CategoryController::store and EventController::store.',
        );
    }

    public function test_event_type_still_declares_mandant_id_as_fillable(): void
    {
        // The reason this is not merely cosmetic: the model WOULD accept the
        // column, so only the merge order and the validation rules stand
        // between a payload and a cross-tenant write.
        $fillable = (new ReflectionClass(EventType::class))->getAttributes(Fillable::class);

        $this->assertCount(1, $fillable);
        $this->assertContains('mandant_id', $fillable[0]->newInstance()->columns);
    }

    /**
     * The `Model::create([...])` array literal of one controller method, with
     * the body of `create()` stripped off.
     */
    private function createLiteralOf(string $class, string $method): string
    {
        $source = File::get((string) (new ReflectionClass($class))->getFileName());

        $start = strpos($source, 'public function '.$method.'(');
        $this->assertIsInt($start, "method {$class}::{$method}() not found");

        $literal = strpos($source, '::create([', $start);
        $this->assertIsInt($literal, "no create() literal in {$class}::{$method}()");

        return substr($source, $literal, 400);
    }

    private function superAdmin(): User
    {
        $user = User::factory()->create();

        RoleUser::create([
            'user_id' => $user->id,
            'role_id' => Role::query()->where('slug', UserRole::SUPER_ADMIN->value)->valueOrFail('id'),
            'mandant_id' => null,
            'team_id' => null,
        ]);

        return $user;
    }
}
