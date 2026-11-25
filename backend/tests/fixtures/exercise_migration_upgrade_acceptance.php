<?php

use App\Models\Center;
use App\Models\CenterUser;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Exercise;
use App\Models\ExerciseAttempt;
use App\Models\ExerciseAttemptAnswer;
use App\Models\Language;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

require_once __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = trim((string) env('ACCEPTANCE_DB_DATABASE', ''));
if (! preg_match('/\Aschool_saas_g5_acceptance_[A-Za-z0-9_]+\z/', $database) || $database === 'school_saas') {
    fwrite(STDERR, "Refusing non-acceptance database.\n");
    exit(2);
}

config([
    'database.connections.mysql_acceptance' => [
        'driver' => 'mysql',
        'host' => (string) env('ACCEPTANCE_DB_HOST'),
        'port' => (int) env('ACCEPTANCE_DB_PORT', 3306),
        'database' => $database,
        'username' => (string) env('ACCEPTANCE_DB_USERNAME'),
        'password' => (string) env('ACCEPTANCE_DB_PASSWORD'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'engine' => 'InnoDB',
    ],
    'database.default' => 'mysql_acceptance',
]);
DB::purge('mysql_acceptance');
DB::setDefaultConnection('mysql_acceptance');

$connection = DB::connection('mysql_acceptance');
$version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
if (! preg_match('/\A8\./', $version)) {
    throw new RuntimeException("MySQL 8 required; detected {$version}.");
}

$targetMigration = '2026_11_24_100000_extend_exercise_answers_for_new_question_types';
$recordedMigrations = $connection->table('migrations')->pluck('migration')->all();
$localMigrations = array_map(
    static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
    glob(database_path('migrations/*.php')) ?: []
);
$pendingMigrations = array_values(array_diff($localMigrations, $recordedMigrations));
if ($pendingMigrations !== [$targetMigration]) {
    throw new RuntimeException(
        'Expected only the assessment migration to be pending on the dedicated acceptance database; found: '
        .implode(', ', $pendingMigrations)
    );
}

foreach (['questions' => 'answer_config', 'exercise_attempt_answers' => 'selected_option_ids', 'exercise_attempts' => 'submission_fingerprint'] as $table => $column) {
    if (Schema::connection('mysql_acceptance')->hasColumn($table, $column)) {
        throw new RuntimeException("Pre-upgrade schema unexpectedly contains {$table}.{$column}.");
    }
}

$centerId = null;
$userId = null;
$result = [];
$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

try {
    $suffix = Str::lower(Str::random(10));
    $now = now();
    $center = Center::create([
        'name' => 'Exercise migration acceptance '.$suffix,
        'slug' => 'exercise-migration-'.$suffix,
        'status' => 'active',
        'timezone' => 'UTC',
        'default_locale' => 'en',
    ]);
    $centerId = (int) $center->id;

    $user = User::create([
        'name' => 'Migration acceptance student '.$suffix,
        'email' => 'migration_'.$suffix.'@acceptance.test',
        'password' => 'Password123!',
        'status' => User::STATUS_ACTIVE,
        'token_version' => 1,
    ]);
    $userId = (int) $user->id;

    $membership = CenterUser::create([
        'center_id' => $centerId,
        'user_id' => $userId,
        'status' => 'active',
    ]);
    $profile = StudentProfile::factory()
        ->forMembership($membership)
        ->create(['status' => StudentProfile::STATUS_ACTIVE]);

    $language = Language::create([
        'center_id' => $centerId,
        'code' => 'en',
        'name' => 'English',
        'native_name' => 'English',
        'is_active' => true,
    ]);
    $level = Level::create([
        'center_id' => $centerId,
        'code' => 'A1',
        'name' => 'Beginner',
        'sort_order' => 1,
        'is_active' => true,
    ]);
    $course = Course::create([
        'center_id' => $centerId,
        'language_id' => $language->id,
        'level_id' => $level->id,
        'title' => 'Migration acceptance course '.$suffix,
        'slug' => 'migration-course-'.$suffix,
        'status' => 'published',
        'published_at' => $now,
    ]);
    $module = CourseModule::create([
        'center_id' => $centerId,
        'course_id' => $course->id,
        'title' => 'Migration module',
        'sort_order' => 1,
        'status' => CourseModule::STATUS_PUBLISHED,
        'published_at' => $now,
    ]);
    $lesson = Lesson::create([
        'center_id' => $centerId,
        'course_module_id' => $module->id,
        'title' => 'Migration acceptance lesson '.$suffix,
        'content_locale' => 'en',
        'status' => Lesson::STATUS_DRAFT,
    ]);
    $exercise = Exercise::create([
        'center_id' => $centerId,
        'lesson_id' => $lesson->id,
        'title' => 'Legacy assessment '.$suffix,
        'max_attempts' => 2,
        'passing_score' => '60.00',
        'status' => Exercise::STATUS_DRAFT,
    ]);
    $singleChoice = Question::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'question_type' => Question::TYPE_SINGLE_CHOICE,
        'prompt' => 'Legacy single choice question',
        'explanation' => 'Legacy explanation remains private',
        'points' => '0.10',
        'sort_order' => 1,
    ]);
    $correctOption = QuestionOption::create([
        'center_id' => $centerId,
        'question_id' => $singleChoice->id,
        'option_text' => 'Correct legacy option',
        'is_correct' => true,
        'sort_order' => 1,
    ]);
    $incorrectOption = QuestionOption::create([
        'center_id' => $centerId,
        'question_id' => $singleChoice->id,
        'option_text' => 'Incorrect legacy option',
        'is_correct' => false,
        'sort_order' => 2,
    ]);
    $trueFalse = Question::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'question_type' => Question::TYPE_TRUE_FALSE,
        'prompt' => 'Legacy true false question',
        'points' => '0.20',
        'sort_order' => 2,
    ]);
    $trueOption = QuestionOption::create([
        'center_id' => $centerId,
        'question_id' => $trueFalse->id,
        'option_text' => 'True',
        'is_correct' => true,
        'sort_order' => 1,
    ]);
    QuestionOption::create([
        'center_id' => $centerId,
        'question_id' => $trueFalse->id,
        'option_text' => 'False',
        'is_correct' => false,
        'sort_order' => 2,
    ]);

    $attempt = ExerciseAttempt::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'student_profile_id' => $profile->id,
        'attempt_number' => 1,
        'status' => ExerciseAttempt::STATUS_GRADED,
        'passing_score_snapshot' => '60.00',
        'show_correct_answers_after_submission_snapshot' => false,
        'score' => '0.10',
        'max_score' => '0.30',
        'percentage' => '33.33',
        'passed' => false,
        'started_at' => $now,
        'submitted_at' => $now,
        'graded_at' => $now,
    ]);
    $legacyAnswer = ExerciseAttemptAnswer::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'exercise_attempt_id' => $attempt->id,
        'question_id' => $singleChoice->id,
        'selected_option_id' => $correctOption->id,
        'correct_option_id_snapshot' => $correctOption->id,
        'question_type_snapshot' => Question::TYPE_SINGLE_CHOICE,
        'prompt_snapshot' => 'Legacy single choice question',
        'explanation_snapshot' => 'Legacy explanation remains private',
        'options_snapshot' => [
            ['id' => $correctOption->id, 'option_text' => 'Correct legacy option', 'sort_order' => 1],
            ['id' => $incorrectOption->id, 'option_text' => 'Incorrect legacy option', 'sort_order' => 2],
        ],
        'points_possible' => '0.10',
        'is_correct' => true,
        'points_awarded' => '0.10',
    ]);
    $legacyBefore = [
        'attempt_id' => (int) $attempt->id,
        'answer_id' => (int) $legacyAnswer->id,
        'correct_option_id' => (int) $correctOption->id,
        'score' => (string) $attempt->score,
        'max_score' => (string) $attempt->max_score,
        'percentage' => (string) $attempt->percentage,
        'passed' => (bool) $attempt->passed,
        'submitted_at' => $attempt->submitted_at->toIso8601String(),
        'graded_at' => $attempt->graded_at->toIso8601String(),
    ];

    $exitCode = Artisan::call('migrate', [
        '--database' => 'mysql_acceptance',
        '--path' => 'database/migrations/2026_11_24_100000_extend_exercise_answers_for_new_question_types.php',
        '--force' => true,
        '--no-interaction' => true,
    ]);
    if ($exitCode !== 0) {
        throw new RuntimeException('Assessment migration command failed: '.Artisan::output());
    }

    $storedAttempt = ExerciseAttempt::query()->findOrFail($legacyBefore['attempt_id']);
    $storedAnswer = ExerciseAttemptAnswer::query()->findOrFail($legacyBefore['answer_id']);
    $assert((int) $storedAnswer->correct_option_id_snapshot === $legacyBefore['correct_option_id'], 'Legacy correct option snapshot changed.');
    $assert((string) $storedAttempt->score === $legacyBefore['score'], 'Legacy score changed.');
    $assert((string) $storedAttempt->max_score === $legacyBefore['max_score'], 'Legacy maximum score changed.');
    $assert((string) $storedAttempt->percentage === $legacyBefore['percentage'], 'Legacy percentage changed.');
    $assert((bool) $storedAttempt->passed === $legacyBefore['passed'], 'Legacy pass state changed.');
    $assert($storedAttempt->submitted_at->toIso8601String() === $legacyBefore['submitted_at'], 'Legacy submitted timestamp changed.');
    $assert($storedAttempt->graded_at->toIso8601String() === $legacyBefore['graded_at'], 'Legacy graded timestamp changed.');
    $assert($storedAnswer->correct_option_ids_snapshot === null, 'New multi-answer snapshot should be nullable for legacy rows.');
    $assert($storedAnswer->answer_config_snapshot === null, 'New config snapshot should be nullable for legacy rows.');
    $assert($storedAnswer->text_answer === null, 'New text answer should be nullable for legacy rows.');
    $assert($storedAnswer->selected_option_ids === null, 'New selected options should be nullable for legacy rows.');

    $trueFalse->answer_config = ['normalization' => ['case_sensitive' => false]];
    $trueFalse->save();
    $assert(
        Question::query()->findOrFail($trueFalse->id)->answer_config === ['normalization' => ['case_sensitive' => false]],
        'New question answer_config JSON did not round-trip.'
    );

    $shortAnswer = Question::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'question_type' => Question::TYPE_SHORT_ANSWER,
        'prompt' => 'New short answer question',
        'points' => '0.10',
        'sort_order' => 3,
        'answer_config' => ['accepted_answers' => ['bonjour']],
    ]);
    $newAttempt = ExerciseAttempt::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'student_profile_id' => $profile->id,
        'attempt_number' => 2,
        'status' => ExerciseAttempt::STATUS_IN_PROGRESS,
        'passing_score_snapshot' => '60.00',
        'show_correct_answers_after_submission_snapshot' => false,
        'score' => null,
        'max_score' => '0.10',
        'percentage' => null,
        'passed' => null,
        'started_at' => $now,
    ]);
    $newAnswer = ExerciseAttemptAnswer::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'exercise_attempt_id' => $newAttempt->id,
        'question_id' => $shortAnswer->id,
        'selected_option_id' => null,
        'selected_option_ids' => [],
        'text_answer' => 'bonjour',
        'correct_option_id_snapshot' => null,
        'correct_option_ids_snapshot' => [],
        'answer_config_snapshot' => ['accepted_answers' => ['bonjour']],
        'question_type_snapshot' => Question::TYPE_SHORT_ANSWER,
        'prompt_snapshot' => 'New short answer question',
        'options_snapshot' => [],
        'points_possible' => '0.10',
        'is_correct' => null,
        'points_awarded' => null,
    ]);
    $storedNewAnswer = ExerciseAttemptAnswer::query()->findOrFail($newAnswer->id);
    $assert($storedNewAnswer->correct_option_id_snapshot === null, 'Nullable correct option snapshot rejected null.');
    $assert($storedNewAnswer->selected_option_ids === [], 'Selected-option JSON did not round-trip.');
    $assert($storedNewAnswer->correct_option_ids_snapshot === [], 'Correct-option JSON did not round-trip.');
    $assert($storedNewAnswer->answer_config_snapshot === ['accepted_answers' => ['bonjour']], 'Answer-config snapshot JSON did not round-trip.');

    $defaultExercise = Exercise::create([
        'center_id' => $centerId,
        'lesson_id' => $lesson->id,
        'title' => 'Passing default check '.$suffix,
        'status' => Exercise::STATUS_DRAFT,
    ]);
    $defaultPassingScore = (string) DB::table('exercises')->where('id', $defaultExercise->id)->value('passing_score');
    $assert($defaultPassingScore === '70.00', 'New exercise database default is not 70.00.');
    $assert((string) $exercise->fresh()->passing_score === '60.00', 'Migration changed a historical passing score.');

    $foreignKey = $connection->select(
        'SELECT COLUMN_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE CONSTRAINT_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? ORDER BY ORDINAL_POSITION',
        [$database, 'exercise_attempt_answers', 'exercise_attempt_answers_correct_option_fk']
    );
    $assert(
        array_map(static fn (object $row): array => [$row->COLUMN_NAME, $row->REFERENCED_COLUMN_NAME], $foreignKey)
            === [['center_id', 'center_id'], ['question_id', 'question_id'], ['correct_option_id_snapshot', 'id']],
        'Recreated composite correct-option foreign key does not match the expected columns.'
    );

    $result = [
        'database' => $database,
        'mysql_version' => $version,
        'migration' => $targetMigration,
        'legacy_single_choice_and_true_false' => 'preserved',
        'legacy_completed_attempt' => $legacyBefore,
        'new_json_columns' => 'round-trip passed',
        'nullable_correct_option_snapshot' => 'passed',
        'composite_foreign_key' => 'passed',
        'historical_passing_score' => '60.00 preserved',
        'new_passing_score_default' => $defaultPassingScore,
    ];
} finally {
    if ($centerId !== null) {
        DB::connection('mysql_acceptance')->transaction(function () use ($centerId, $userId): void {
            DB::table('exercise_attempt_answers')->where('center_id', $centerId)->delete();
            DB::table('exercise_attempts')->where('center_id', $centerId)->delete();
            DB::table('student_profiles')->where('center_id', $centerId)->delete();

            $exerciseIds = DB::table('exercises')->where('center_id', $centerId)->pluck('id');
            if ($exerciseIds->isNotEmpty()) {
                $questionIds = DB::table('questions')->whereIn('exercise_id', $exerciseIds)->pluck('id');
                if ($questionIds->isNotEmpty()) {
                    DB::table('question_options')->whereIn('question_id', $questionIds)->delete();
                    DB::table('questions')->whereIn('id', $questionIds)->delete();
                }
                DB::table('exercises')->where('center_id', $centerId)->delete();
            }

            DB::table('lessons')->where('center_id', $centerId)->update([
                'draft_revision_id' => null,
                'published_revision_id' => null,
            ]);
            DB::table('lessons')->where('center_id', $centerId)->delete();
            DB::table('course_modules')->where('center_id', $centerId)->delete();
            $courseIds = DB::table('courses')->where('center_id', $centerId)->pluck('id');
            if ($courseIds->isNotEmpty()) {
                DB::table('course_outcomes')->whereIn('course_id', $courseIds)->delete();
                DB::table('course_translations')->whereIn('course_id', $courseIds)->delete();
            }
            DB::table('courses')->where('center_id', $centerId)->delete();
            DB::table('languages')->where('center_id', $centerId)->delete();
            DB::table('levels')->where('center_id', $centerId)->delete();
            DB::table('center_user_role')
                ->whereIn('center_user_id', DB::table('center_user')->where('center_id', $centerId)->select('id'))
                ->delete();
            DB::table('center_user')->where('center_id', $centerId)->delete();
            DB::table('centers')->where('id', $centerId)->delete();
            if ($userId !== null) {
                DB::table('users')->where('id', $userId)->delete();
            }
        });
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
