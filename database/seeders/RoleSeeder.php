<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            1 => 'Student',
            2 => 'Internship Coordinator',
            3 => 'Supervisor',
            4 => 'Administrator',
        ];

        foreach ($roles as $id => $name) {
            Role::updateOrCreate(['id' => $id], ['role_name' => $name]);
        }
    }
}
