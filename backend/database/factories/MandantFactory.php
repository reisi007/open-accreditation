<?php

namespace Database\Factories;

use App\Models\Mandant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mandant>
 */
class MandantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'logo_path' => null,
            'header_path' => null,
            'impressum_text' => null,
            'privacy_text' => null,
            'smtp_config' => null,
            'teams_enabled' => false,
            'is_primary' => false,
            'is_active' => true,
        ];
    }
}
