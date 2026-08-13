<?php

namespace Database\Factories;

use App\Models\Mandant;
use App\Models\MandantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MandantDomain>
 */
class MandantDomainFactory extends Factory
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
            'hostname' => fake()->unique()->domainName(),
        ];
    }
}
