<?php

namespace Tests\Unit;

use App\Models\Accreditation;
use App\Models\Application;
use App\Models\Blacklist;
use App\Models\Category;
use App\Models\Event;
use App\Models\Mandant;
use App\Models\SubAccreditation;
use App\Models\SubApplication;
use App\Models\Team;
use App\Models\User;
use App\Support\MandantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test for the route-model-binding tenant-isolation safety net
 * (fix/be-r2). Every core mandant-scoped model overrides
 * `resolveRouteBindingQuery()` so a bound instance is only resolved when it
 * belongs to the current mandant (host-derived). This guards against a single
 * missed `forMandant()` in a controller leaking another tenant's row through
 * an unscoped binding.
 */
class TenantIsolationBindingTest extends TestCase
{
    use RefreshDatabase;

    private Mandant $mandantA;

    private Mandant $mandantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mandantA = Mandant::factory()->create(['slug' => 'va', 'name' => 'VA']);
        $this->mandantB = Mandant::factory()->create(['slug' => 'vb', 'name' => 'VB']);
    }

    protected function tearDown(): void
    {
        MandantContext::reset();
        parent::tearDown();
    }

    public function test_direct_mandant_models_reject_foreign_binding(): void
    {
        $catB = $this->mandantB->categories()->create(['name' => 'C', 'slug' => 'c-b']);
        $eventB = $this->mandantB->events()->create(['title' => 'E']);
        $teamB = $this->mandantB->teams()->create(['name' => 'T', 'slug' => 't-b']);
        $blacklistB = Blacklist::create(['mandant_id' => $this->mandantB->id, 'email' => 'x@y.z']);

        MandantContext::set($this->mandantA);

        $this->assertNull((new Category)->resolveRouteBinding((string) $catB->id));
        $this->assertNull((new Event)->resolveRouteBinding((string) $eventB->id));
        $this->assertNull((new Team)->resolveRouteBinding((string) $teamB->id));
        $this->assertNull((new Blacklist)->resolveRouteBinding((string) $blacklistB->id));

        // A model of the current mandant still resolves normally.
        $catA = $this->mandantA->categories()->create(['name' => 'C', 'slug' => 'c-a']);
        $this->assertNotNull((new Category)->resolveRouteBinding((string) $catA->id));
    }

    public function test_relation_scoped_models_reject_foreign_binding(): void
    {
        $catB = $this->mandantB->categories()->create(['name' => 'C', 'slug' => 'c-b']);
        $accB = $this->mandantB->accreditations()->create(['category_id' => $catB->id, 'scope' => 'season', 'quota' => 1]);
        $subB = $accB->subAccreditations()->create(['type' => 'park', 'quota' => 1]);
        $user = User::factory()->create();
        $appB = Application::create(['accreditation_id' => $accB->id, 'user_id' => $user->id]);
        $subAppB = SubApplication::create(['sub_accreditation_id' => $subB->id, 'application_id' => $appB->id, 'user_id' => $user->id]);

        MandantContext::set($this->mandantA);

        $this->assertNull((new Accreditation)->resolveRouteBinding((string) $accB->id));
        $this->assertNull((new SubAccreditation)->resolveRouteBinding((string) $subB->id));
        $this->assertNull((new Application)->resolveRouteBinding((string) $appB->id));
        $this->assertNull((new SubApplication)->resolveRouteBinding((string) $subAppB->id));

        // A model whose parent accreditation belongs to the current mandant resolves.
        $catA = $this->mandantA->categories()->create(['name' => 'C', 'slug' => 'c-a']);
        $accA = $this->mandantA->accreditations()->create(['category_id' => $catA->id, 'scope' => 'season', 'quota' => 1]);
        $this->assertNotNull((new Accreditation)->resolveRouteBinding((string) $accA->id));
    }

    public function test_binding_is_unscoped_when_no_mandant_resolved(): void
    {
        $catB = $this->mandantB->categories()->create(['name' => 'C', 'slug' => 'c-b']);

        MandantContext::reset();

        // Without a resolved mandant (seeders, console, tests) bindings stay unscoped.
        $this->assertNotNull((new Category)->resolveRouteBinding((string) $catB->id));
    }
}
