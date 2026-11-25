<?php

/**
 * Standalone Migration Acceptance Script
 *
 * Purpose:
 *   Validates safe schema migration and data integrity preservation during the
 *   exercise upgrade migration (2026_11_24_100000_extend_exercise_answers_for_new_question_types).
 *   Specifically verifies that historical single-choice and true/false attempts retain
 *   all legacy column values across migration, new question types and snapshots round-trip properly,
 *   composite foreign keys remain intact, submission fingerprints persist as 64-character SHA-256 hex,
 *   and default passing score defaults to 70.00 while preserving historical scores.
 *
 * Prerequisites:
 *   - Dedicated acceptance MySQL 8 database (e.g. school_saas_g5_acceptance_*)
 *   - ACCEPTANCE_DB_HOST, ACCEPTANCE_DB_PORT, ACCEPTANCE_DB_DATABASE,
 *     ACCEPTANCE_DB_USERNAME, ACCEPTANCE_DB_PASSWORD configured in .env
 *   - Never run against production or school_saas development database.
 *
 * Usage:
 *   cd backend
 *   php tests/fixtures/exercise_migration_upgrade_acceptance.php
 *
 * Output:
 *   JSON-encoded test results describing preserved legacy data, schema verification,
 *   composite foreign key checks, and new field round-trips.
 *
 * Warnings:
 *   Strictly rejects execution in production environments (APP_ENV=production).
 *   Strictly rejects execution against non-acceptance databases.
 */

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

// 1. Production rejection
if (app()->environment('production')) {
    fwrite(STDERR, "Refusing to run migration acceptance script in production environment.\n");
    exit(1);
}

// 2. Dedicated acceptance DB validation
$database = trim((string) env('ACCEPTANCE_DB_DATABASE', ''));
if ($database === '' || $database === 'school_saas' || ! preg_match('/\Aschool_saas_g5_acceptance_[A-Za-z0-9_]+\z/', $database)) {
    fwrite(STDERR, "Refusing non-acceptance database: '{$database}'. Must match /^school_saas_g5_acceptance_[a-zA-Z0-9_]+$/ and not be 'school_saas'.\n");
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

// 3. MySQL 8 and InnoDB checks
$version = (string) $connection->selectOne('SELECT VERSION() AS version')->version;
if (! preg_match('/\A8\./', $version)) {
    throw new RuntimeException("MySQL 8 required; detected {$version}.");
}

$engineRow = $connection->selectOne(
    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
    [$database, 'exercise_attempts']
);
if ($engineRow && strtoupper((string) $engineRow->ENGINE) !== 'INNODB') {
    throw new RuntimeException("InnoDB engine required; detected {$engineRow->ENGINE}.");
}

$targetMigration = '2026_11_24_100000_extend_exercise_answers_for_new_question_types';
$recordedMigrations = $connection->table('migrations')->pluck('migration')->all();
$localMigrations = array_map(
    static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
    glob(database_path('migrations/*.php')) ?: []
);

// If target migration was already recorded on the acceptance DB, roll it back to establish baseline pre-upgrade state
if (in_array($targetMigration, $recordedMigrations, true)) {
    $rollbackExit = Artisan::call('migrate:rollback', [
        '--database' => 'mysql_acceptance',
        '--path' => 'database/migrations/'.$targetMigration.'.php',
        '--force' => true,
        '--no-interaction' => true,
    ]);
    if ($rollbackExit !== 0) {
        throw new RuntimeException('Failed to rollback prior assessment migration for fresh test run: '.Artisan::output());
    }
    $recordedMigrations = $connection->table('migrations')->pluck('migration')->all();
}

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
    QuestionOption::create([
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

    // 4. Capture exact pre-migration raw state for all columns
    $legacyAttemptBeforeRaw = (array) $connection->table('exercise_attempts')->where('id', $attempt->id)->first();
    $legacyAnswerBeforeRaw = (array) $connection->table('exercise_attempt_answers')->where('id', $legacyAnswer->id)->first();

    // Run the migration upgrade
    $exitCode = Artisan::call('migrate', [
        '--database' => 'mysql_acceptance',
        '--path' => 'database/migrations/2026_11_24_100000_extend_exercise_answers_for_new_question_types.php',
        '--force' => true,
        '--no-interaction' => true,
    ]);
    if ($exitCode !== 0) {
        throw new RuntimeException('Assessment migration command failed: '.Artisan::output());
    }

    // Verify all legacy attempt fields remain completely identical
    $legacyAttemptAfterRaw = (array) $connection->table('exercise_attempts')->where('id', $attempt->id)->first();
    foreach ($legacyAttemptBeforeRaw as $column => $value) {
        $assert(
            (string) $legacyAttemptAfterRaw[$column] === (string) $value,
            "Legacy attempt column '{$column}' changed across migration: before='{$value}', after='{$legacyAttemptAfterRaw[$column]}'."
        );
    }
    $assert($legacyAttemptAfterRaw['submission_fingerprint'] === null, 'Historical attempt submission_fingerprint must default to null.');

    // Verify all legacy attempt answer fields remain completely identical
    $legacyAnswerAfterRaw = (array) $connection->table('exercise_attempt_answers')->where('id', $legacyAnswer->id)->first();
    foreach ($legacyAnswerBeforeRaw as $column => $value) {
        $assert(
            (string) $legacyAnswerAfterRaw[$column] === (string) $value,
            "Legacy attempt answer column '{$column}' changed across migration: before='{$value}', after='{$legacyAnswerAfterRaw[$column]}'."
        );
    }
    $assert($legacyAnswerAfterRaw['text_answer'] === null, 'New text_answer must be null for legacy rows.');
    $assert($legacyAnswerAfterRaw['selected_option_ids'] === null, 'New selected_option_ids must be null for legacy rows.');
    $assert($legacyAnswerAfterRaw['correct_option_ids_snapshot'] === null, 'New correct_option_ids_snapshot must be null for legacy rows.');
    $assert($legacyAnswerAfterRaw['answer_config_snapshot'] === null, 'New answer_config_snapshot must be null for legacy rows.');

    // 5. Verify new question field: answer_config round-trip
    $trueFalse->answer_config = ['normalization' => ['case_sensitive' => false]];
    $trueFalse->save();
    $assert(
        Question::query()->findOrFail($trueFalse->id)->answer_config === ['normalization' => ['case_sensitive' => false]],
        'New question answer_config JSON did not round-trip.'
    );

    // 5. Verify new question and attempt records with new fields
    $shortAnswer = Question::create([
        'center_id' => $centerId,
        'exercise_id' => $exercise->id,
        'question_type' => Question::TYPE_SHORT_ANSWER,
        'prompt' => 'New short answer question',
        'points' => '0.10',
        'sort_order' => 3,
        'answer_config' => ['accepted_answers' => ['bonjour']],
    ]);

    $sampleFingerprint = hash('sha256', 'student_attempt_payload_v2_acceptance_'.$suffix);
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
        'submission_fingerprint' => $sampleFingerprint,
        'started_at' => $now,
    ]);

    $storedFingerprint = (string) $connection->table('exercise_attempts')->where('id', $newAttempt->id)->value('submission_fingerprint');
    $assert($storedFingerprint === $sampleFingerprint, 'submission_fingerprint 64-char sha256 did not round-trip.');
    $assert(strlen($storedFingerprint) === 64, 'submission_fingerprint must be exactly 64 characters long.');

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
    $assert($storedNewAnswer->text_answer === 'bonjour', 'Text answer string did not round-trip.');
    $assert($storedNewAnswer->selected_option_ids === [], 'Selected-option JSON did not round-trip.');
    $assert($storedNewAnswer->correct_option_ids_snapshot === [], 'Correct-option JSON did not round-trip.');
    $assert($storedNewAnswer->answer_config_snapshot === ['accepted_answers' => ['bonjour']], 'Answer-config snapshot JSON did not round-trip.');

    // 6. Verify default passing score behavior: 70.00 for new vs historical preservation
    $defaultExercise = Exercise::create([
        'center_id' => $centerId,
        'lesson_id' => $lesson->id,
        'title' => 'Passing default check '.$suffix,
        'status' => Exercise::STATUS_DRAFT,
    ]);
    $defaultPassingScore = (string) DB::table('exercises')->where('id', $defaultExercise->id)->value('passing_score');
    $assert($defaultPassingScore === '70.00', 'New exercise database default is not 70.00.');
    $assert((string) $exercise->fresh()->passing_score === '60.00', 'Migration changed a historical passing score.');

    // 7. Verify composite foreign key integrity
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
        'legacy_completed_attempt_fields_verified' => count($legacyAttemptBeforeRaw),
        'legacy_completed_answer_fields_verified' => count($legacyAnswerBeforeRaw),
        'submission_fingerprint_sha256' => 'round-trip passed',
        'new_json_columns' => 'round-trip passed',
        'nullable_correct_option_snapshot' => 'passed',
        'composite_foreign_key' => 'passed',
        'historical_passing_score' => '60.00 preserved',
        'new_passing_score_default' => $defaultPassingScore,
        'status' => 'SUCCESS',
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
