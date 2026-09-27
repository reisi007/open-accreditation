<?php

namespace Database\Factories;

use App\Models\Mandant;
use App\Models\Venue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Venue>
 */
class VenueFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * `is_active` defaults to `true` (the DB column default as well) — a venue
     * is created usable, deactivation is an explicit later write.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mandant_id' => Mandant::factory(),
            'name' => fake()->unique()->city(),
            'is_active' => true,
        ];
    }

    /**
     * A deactivated venue — still referenced, no longer offered for new
     * assignments.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
