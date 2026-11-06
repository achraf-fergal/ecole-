<?php

namespace Database\\Seeders;

use App\\Models\\Center;
use App\\Models\\Course;
use App\\Models\\Language;
use App\\Models\\Level;
use Illuminate\\Database\\Seeder;
use RuntimeException;

class DemoCourseSeeder extends Seeder
{
    /**
     * Local-only sample content. Never run this seeder in production.
     */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('DemoCourseSeeder is only allowed in local/testing environments.');
        }

        $center = Center::updateOrCreate(
            ['slug' => 'demo-center'],
            [
                'name' => 'Demo Language Center',
                'email' => 'demo@example.test',
                'phone' => null,
                'logo_url' => null,
                'status' => 'active',
                'timezone' => 'Africa/Casablanca',
                'default_locale' => 'en',
            ]
        );

        $english = Language::updateOrCreate(
            ['center_id' => $center->id, 'code' => 'en'],
            ['name' => 'English', 'native_name' => 'English', 'is_active' => true, 'sort_order' => 1]
        );

        $french = Language::updateOrCreate(
            ['center_id' => $center->id, 'code' => 'fr'],
            ['name' => 'French', 'native_name' => 'Français', 'is_active' => true, 'sort_order' => 2]
        );

        $levels = [];
        foreach ([
            ['code' => 'A1', 'name' => 'Beginner', 'sort_order' => 1],
            ['code' => 'A2', 'name' => 'Elementary', 'sort_order' => 2],
            ['code' => 'B1', 'name' => 'Intermediate', 'sort_order' => 3],
            ['code' => 'B2', 'name' => 'Upper Intermediate', 'sort_order' => 4],
        ] as $item) {
            $levels[$item['code']] = Level::updateOrCreate(
                ['center_id' => $center->id, 'code' => $item['code']],
                [
                    'level_template_id' => null,
                    'name' => $item['name'],
                    'description' => null,
                    'sort_order' => $item['sort_order'],
                    'is_active' => true,
                ]
            );
        }

        $courses = [
            [
                'slug' => 'general-english-a1',
                'title' => 'General English A1',
                'language' => $english,
                'level' => $levels['A1'],
                'format' => 'hybrid',
                'hours' => 40,
                'description' => 'Build a strong foundation in English with practical vocabulary and everyday communication.',
            ],
            [
                'slug' => 'english-communication-a2',
                'title' => 'English Communication A2',
                'language' => $english,
                'level' => $levels['A2'],
                'format' => 'in_person',
                'hours' => 48,
                'description' => 'Improve your confidence in common conversations and everyday situations.',
            ],
            [
                'slug' => 'business-english-b1',
                'title' => 'Business English B1',
                'language' => $english,
                'level' => $levels['B1'],
                'format' => 'online',
                'hours' => 36,
                'description' => 'Develop communication skills for meetings, emails and professional situations.',
            ],
            [
                'slug' => 'english-fluency-b2',
                'title' => 'English Fluency B2',
                'language' => $english,
                'level' => $levels['B2'],
                'format' => 'hybrid',
                'hours' => 50,
                'description' => 'Strengthen fluency, comprehension and advanced communication skills.',
            ],
            [
                'slug' => 'french-debutant-a1',
                'title' => 'French for Beginners A1',
                'language' => $french,
                'level' => $levels['A1'],
                'format' => 'in_person',
                'hours' => 40,
                'description' => 'Start speaking French through essential vocabulary and practical activities.',
            ],
            [
                'slug' => 'french-intermediaire-b1',
                'title' => 'French Intermediate B1',
                'language' => $french,
                'level' => $levels['B1'],
                'format' => 'online',
                'hours' => 42,
                'description' => 'Improve your spoken and written French for study, work and daily life.',
            ],
        ];

        foreach ($courses as $index => $item) {
            Course::updateOrCreate(
                ['center_id' => $center->id, 'slug' => $item['slug']],
                [
                    'language_id' => $item['language']->id,
                    'level_id' => $item['level']->id,
                    'title' => $item['title'],
                    'short_description' => $item['description'],
                    'description' => $item['description'],
                    'prerequisites' => null,
                    'study_format' => $item['format'],
                    'duration_hours' => $item['hours'],
                    'cover_image_url' => null,
                    'price' => null,
                    'currency' => 'MAD',
                    'status' => 'published',
                    'sort_order' => $index + 1,
                    'published_at' => now(),
                ]
            );
        }

        $this->command?->info('Demo center created: demo-center (6 published courses).');
    }
}
