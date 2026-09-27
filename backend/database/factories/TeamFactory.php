<?php

namespace Database\Factories;

use App\Models\Mandant;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mandant_id' => Mandant::factory(),
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            // W12: the location is a reference into the mandant's venue master
            // data, not free text. A factory team has no venue by default.
            'venue_id' => null,
        ];
    }
}
