<?php

namespace Tests\Feature;

use App\Models\Mandant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MandantSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_primary_and_demo_mandants_with_domains(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('mandants', [
            'slug' => 'main',
            'name' => 'Hauptseite',
            'is_primary' => true,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('mandants', [
            'slug' => 'bundesliga',
            'name' => 'Bundesliga',
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('mandant_domains', ['hostname' => 'localhost']);
        $this->assertDatabaseHas('mandant_domains', ['hostname' => 'accreditation.test']);
        $this->assertDatabaseHas('mandant_domains', ['hostname' => 'www.accreditation.test']);
        $this->assertDatabaseHas('mandant_domains', ['hostname' => 'bundesliga.test']);
        $this->assertDatabaseHas('mandant_domains', ['hostname' => 'www.bundesliga.test']);

        $main = Mandant::where('slug', 'main')->firstOrFail();
        $bundesliga = Mandant::where('slug', 'bundesliga')->firstOrFail();

        $this->assertTrue($main->isPrimary());
        $this->assertTrue($main->domains()->where('hostname', 'localhost')->exists());
        $this->assertTrue($main->domains()->where('hostname', 'accreditation.test')->exists());
        $this->assertTrue($main->domains()->where('hostname', 'www.accreditation.test')->exists());
        $this->assertFalse($bundesliga->isPrimary());
        $this->assertSame(2, $bundesliga->domains()->count());
    }

    public function test_seeder_is_idempotent_for_mandants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('mandants', 2);
        $this->assertDatabaseCount('mandant_domains', 5);
    }
}
