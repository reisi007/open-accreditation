<?php

namespace Database\Factories;

use App\Models\Mandant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Bind the account to a mandant — the per-mandant email uniqueness scope
     * (BE-R1). `null` creates a global account (`mandant_id = null`, e.g.
     * bootstrap super admin); that is also the implicit factory default.
     */
    public function forMandant(?Mandant $mandant): static
    {
        return $this->state(fn (): array => [
            'mandant_id' => $mandant?->id,
        ]);
    }
}
