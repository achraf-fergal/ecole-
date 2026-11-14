<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'key' => 'center_admin',
                'name' => 'Center Admin',
                'description' => 'Manages one language center.',
                'is_system' => true,
            ],
            [
                'key' => 'teacher',
                'name' => 'Teacher',
                'description' => 'Manages assigned groups and learning activities.',
                'is_system' => true,
            ],
            [
                'key' => 'student',
                'name' => 'Student',
                'description' => 'Accesses enrolled courses and learning content.',
                'is_system' => true,
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['key' => $role['key']],
                [
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'is_system' => $role['is_system'],
                ]
            );
        }
    }
}
