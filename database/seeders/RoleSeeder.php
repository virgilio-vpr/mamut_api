<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['system_admin', 'aux_admin', 'planner', 'manager', 'client', 'maintainer'] as $name) {
            Role::query()->firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }
    }
}
