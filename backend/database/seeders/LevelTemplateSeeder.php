<?php

namespace Database\Seeders;

use App\Models\LevelTemplate;
use Illuminate\Database\Seeder;

class LevelTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = ['A1', 'A2', 'B1', 'B2', 'C1', 'C2'];

        foreach ($templates as $index => $code) {
            LevelTemplate::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $code,
                    'description' => null,
                    'sort_order' => $index + 1,
                    'is_active' => true,
                ]
            );
        }
    }
}
