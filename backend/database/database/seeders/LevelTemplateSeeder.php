<?php

namespace Database\Seeders;

use App\Models\LevelTemplate;
use Illuminate\Database\Seeder;

class LevelTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'code' => 'A1',
                'name' => 'Beginner',
                'description' => 'Basic language knowledge and simple communication.',
                'sort_order' => 1,
            ],
            [
                'code' => 'A2',
                'name' => 'Elementary',
                'description' => 'Basic everyday communication and familiar topics.',
                'sort_order' => 2,
            ],
            [
                'code' => 'B1',
                'name' => 'Intermediate',
                'description' => 'Independent communication in familiar situations.',
                'sort_order' => 3,
            ],
            [
                'code' => 'B2',
                'name' => 'Upper Intermediate',
                'description' => 'More fluent communication across different topics.',
                'sort_order' => 4,
            ],
            [
                'code' => 'C1',
                'name' => 'Advanced',
                'description' => 'Effective communication in complex situations.',
                'sort_order' => 5,
            ],
            [
                'code' => 'C2',
                'name' => 'Proficiency',
                'description' => 'Advanced understanding and precise expression.',
                'sort_order' => 6,
            ],
        ];

        foreach ($templates as $template) {
            LevelTemplate::updateOrCreate(
                ['code' => $template['code']],
                [
                    'name' => $template['name'],
                    'description' => $template['description'],
                    'sort_order' => $template['sort_order'],
                    'is_active' => true,
                ]
            );
        }
    }
}
