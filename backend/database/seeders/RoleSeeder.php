<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Seeds the five system roles idempotently (`firstOrCreate` on the unique
 * slug). The actual role assignments (e.g. the admin as super_admin) are made
 * in the DatabaseSeeder.
 */
class RoleSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        foreach (UserRole::cases() as $role) {
            Role::firstOrCreate(
                ['slug' => $role->value],
                ['name' => $role->label(), 'description' => $role->description()],
            );
        }
    }
}
