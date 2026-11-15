<?php

namespace Database\Seeders;

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LearningDemoSeeder extends Seeder
{
    public function run(): void
    {
        // This demo seeder must never modify production data.
        if (! app()->environment('local')) {
            throw new RuntimeException(
                'LearningDemoSeeder can only run in the local environment.'
            );
        }

        $result = DB::transaction(function (): array {
            // Resolve the existing demo center and published course.
            $center = Center::query()
                ->where('slug', 'demo-center')
                ->firstOrFail();

            if (! $center->isActive()) {
                throw new RuntimeException(
                    'The demo center must be active.'
                );
            }

            $course = Course::query()
                ->forCenter($center->id)
                ->where('slug', 'general-english-a1')
                ->published()
                ->firstOrFail();

            // Resolve existing demo accounts.
            $student = User::query()
                ->where('email', 'student@example.com')
                ->firstOrFail();

            $teacher = User::query()
                ->where('email', 'teacher@example.com')
                ->firstOrFail();

            $admin = User::query()
                ->where('email', 'admin@example.com')
                ->firstOrFail();

            $this->assertVerifiedActiveUser($student, 'Student');
            $this->assertVerifiedActiveUser($teacher, 'Teacher');
            $this->assertVerifiedActiveUser($admin, 'Center admin');

            // Resolve existing center memberships.
            $studentMembership = $this->findMembership(
                $center,
                $student,
                'student'
            );

            $teacherMembership = $this->findMembership(
                $center,
                $teacher,
                'teacher'
            );

            $adminMembership = $this->findMembership(
                $center,
                $admin,
                'center_admin'
            );

            $this->assertActiveMembership(
                $teacherMembership,
                'Teacher'
            );

            $this->assertActiveMembership(
                $adminMembership,
                'Center admin'
            );

            // Reactivate only the existing approved demo student membership.
            if ($studentMembership->status !== 'active') {
                $studentMembership->status = 'active';
                $studentMembership->save();
            }

            // Create the student's center-specific profile if missing.
            $studentProfile = StudentProfile::query()->firstOrCreate(
                [
                    'center_id' => $center->id,
                    'user_id' => $student->id,
                ],
                [
                    'student_code' => 'DEMO-STU-0006',
                    'status' => StudentProfile::STATUS_ACTIVE,
                ]
            );

            // Keep this local demo profile available for the learning test.
            if (! $studentProfile->isActive()) {
                $studentProfile->status = StudentProfile::STATUS_ACTIVE;
                $studentProfile->save();
            }

            // Create the first published course module.
            $module = CourseModule::query()->firstOrCreate(
                [
                    'center_id' => $center->id,
                    'course_id' => $course->id,
                    'title' => 'Unit 1: Getting Started',
                ],
                [
                    'description' => 'Build the foundations of English communication.',
                    'sort_order' => 1,
                    'status' => CourseModule::STATUS_PUBLISHED,
                    'published_at' => now(),
                ]
            );

            if (! $module->isPublished()) {
                throw new RuntimeException(
                    'The demo course module exists but is not published.'
                );
            }

            // Educational lesson content.
            $lessonDefinitions = [
                [
                    'title' => 'The English Alphabet',
                    'description' => 'Learn the 26 letters of the English alphabet.',
                    'body' => "The English alphabet contains 26 letters.\n\n"
                        . "Uppercase letters:\n"
                        . "A B C D E F G H I J K L M N O P Q R S T U V W X Y Z\n\n"
                        . "Lowercase letters:\n"
                        . "a b c d e f g h i j k l m n o p q r s t u v w x y z\n\n"
                        . "Practice:\n"
                        . "1. Read the alphabet slowly.\n"
                        . "2. Say each letter aloud.\n"
                        . "3. Spell your first name in English.",
                    'duration_minutes' => 12,
                    'sort_order' => 1,
                ],
                [
                    'title' => 'Basic Greetings',
                    'description' => 'Learn common greetings and polite expressions.',
                    'body' => "Greetings are used to start a conversation.\n\n"
                        . "Common greetings:\n"
                        . "- Hello.\n"
                        . "- Hi.\n"
                        . "- Good morning.\n"
                        . "- Good afternoon.\n"
                        . "- Good evening.\n\n"
                        . "Common questions:\n"
                        . "- How are you?\n"
                        . "- How is your day?\n\n"
                        . "Useful responses:\n"
                        . "- I am fine, thank you.\n"
                        . "- I am good.\n"
                        . "- Nice to meet you.\n\n"
                        . "Practice:\n"
                        . "Write a short greeting conversation between two people.",
                    'duration_minutes' => 15,
                    'sort_order' => 2,
                ],
                [
                    'title' => 'Introducing Yourself',
                    'description' => 'Learn how to introduce yourself in English.',
                    'body' => "You can use the following expressions to introduce yourself.\n\n"
                        . "Useful sentences:\n"
                        . "- My name is Alex.\n"
                        . "- I am from Morocco.\n"
                        . "- I live in Casablanca.\n"
                        . "- I am a student.\n"
                        . "- I speak Arabic and I am learning English.\n\n"
                        . "Example:\n"
                        . "Hello! My name is Alex. I am from Morocco. "
                        . "I live in Casablanca. I am learning English.\n\n"
                        . "Practice:\n"
                        . "Write five sentences about yourself using the examples above.",
                    'duration_minutes' => 18,
                    'sort_order' => 3,
                ],
            ];

            $lessonIds = [];

            foreach ($lessonDefinitions as $definition) {
                $lesson = Lesson::query()->firstOrCreate(
                    [
                        'center_id' => $center->id,
                        'course_module_id' => $module->id,
                        'title' => $definition['title'],
                    ],
                    [
                        'description' => $definition['description'],
                        'content_type' => Lesson::TYPE_TEXT,
                        'body' => $definition['body'],
                        'content_locale' => 'en',
                        'duration_minutes' => $definition['duration_minutes'],
                        'sort_order' => $definition['sort_order'],
                        'status' => Lesson::STATUS_PUBLISHED,
                        'published_at' => now(),
                    ]
                );

                if (! $lesson->isPublished()) {
                    throw new RuntimeException(
                        "Demo lesson is not published: {$lesson->title}"
                    );
                }

                $lessonIds[] = $lesson->id;
            }

            // Create the teaching group with the existing teacher.
            $cohort = Cohort::query()->firstOrCreate(
                [
                    'center_id' => $center->id,
                    'code' => 'DEMO-GEN-A1-2026',
                ],
                [
                    'course_id' => $course->id,
                    'teacher_user_id' => $teacher->id,
                    'name' => 'General English A1 - Demo Group',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonths(3),
                    'capacity' => 15,
                    'location_label' => 'Demo Classroom',
                    'status' => Cohort::STATUS_IN_PROGRESS,
                ]
            );

            if (
                (int) $cohort->course_id !== (int) $course->id ||
                (int) $cohort->teacher_user_id !== (int) $teacher->id ||
                $cohort->isCancelled()
            ) {
                throw new RuntimeException(
                    'The existing demo cohort has conflicting configuration.'
                );
            }

            // Grant the existing student active access to this cohort.
            $enrollment = Enrollment::query()->firstOrCreate(
                [
                    'center_id' => $center->id,
                    'student_profile_id' => $studentProfile->id,
                    'cohort_id' => $cohort->id,
                ],
                [
                    'granted_by_user_id' => $admin->id,
                    'status' => Enrollment::STATUS_ACTIVE,
                    'start_at' => now()->subDay(),
                    'end_at' => now()->addMonths(3),
                ]
            );

            if (! $enrollment->isActiveAt()) {
                throw new RuntimeException(
                    'The existing demo enrollment does not grant active access.'
                );
            }

            return [
                'center_id' => $center->id,
                'course_id' => $course->id,
                'module_id' => $module->id,
                'lesson_ids' => $lessonIds,
                'student_profile_id' => $studentProfile->id,
                'cohort_id' => $cohort->id,
                'enrollment_id' => $enrollment->id,
            ];
        });

        $this->command?->info('Learning demo data prepared successfully.');

        $this->command?->table(
            ['Record', 'ID'],
            [
                ['Center', $result['center_id']],
                ['Course', $result['course_id']],
                ['Module', $result['module_id']],
                ['Student Profile', $result['student_profile_id']],
                ['Cohort', $result['cohort_id']],
                ['Enrollment', $result['enrollment_id']],
            ]
        );

        $this->command?->info(
            'Created or verified lesson IDs: ' .
            implode(', ', $result['lesson_ids'])
        );
    }

    /**
     * Resolve a center membership and verify its required role.
     */
    private function findMembership(
        Center $center,
        User $user,
        string $role
    ): CenterUser {
        $membership = CenterUser::query()
            ->where('center_id', $center->id)
            ->where('user_id', $user->id)
            ->with('roles')
            ->firstOrFail();

        if (! $membership->roles()->where('key', $role)->exists()) {
            throw new RuntimeException(
                "The user does not have the required {$role} role."
            );
        }

        return $membership;
    }

    /**
     * Verify that a demo account is active and its email is verified.
     */
    private function assertVerifiedActiveUser(
        User $user,
        string $label
    ): void {
        if (! $user->isActive() || $user->email_verified_at === null) {
            throw new RuntimeException(
                "{$label} account must be active and email verified."
            );
        }
    }

    /**
     * Verify that an administrative or teaching membership is active.
     */
    private function assertActiveMembership(
        CenterUser $membership,
        string $label
    ): void {
        if ($membership->status !== 'active') {
            throw new RuntimeException(
                "{$label} membership must be active."
            );
        }
    }
}
