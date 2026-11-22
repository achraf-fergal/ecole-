<?php

require_once __DIR__.'/../../vendor/autoload.php';

use App\Models\Center;
use App\Models\Course;
use App\Models\Exercise;
use App\Models\Lesson;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Learning\ExerciseAttemptService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

// Strict connection configuration from parent acceptance environment
$host = getenv('ACCEPTANCE_DB_HOST');
$port = getenv('ACCEPTANCE_DB_PORT') ?: '3306';
$database = getenv('ACCEPTANCE_DB_DATABASE');
$username = getenv('ACCEPTANCE_DB_USERNAME');
$password = getenv('ACCEPTANCE_DB_PASSWORD') !== false ? getenv('ACCEPTANCE_DB_PASSWORD') : '';

if (! $host || ! $database || ! $username) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing ACCEPTANCE_DB_* environment variables for worker connection.',
    ]);
    exit(1);
}

config([
    'database.connections.mysql_acceptance' => [
        'driver' => 'mysql',
        'host' => $host,
        'port' => (int) $port,
        'database' => $database,
        'username' => $username,
        'password' => $password,
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'prefix_indexes' => true,
        'strict' => true,
        'engine' => null,
    ],
    'database.default' => 'mysql_acceptance',
]);

DB::purge('mysql_acceptance');
DB::setDefaultConnection('mysql_acceptance');

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$courseId = isset($argv[2]) ? (int) $argv[2] : 0;
$lessonId = isset($argv[3]) ? (int) $argv[3] : 0;
$exerciseId = isset($argv[4]) ? (int) $argv[4] : 0;
$profileId = isset($argv[5]) ? (int) $argv[5] : 0;
$centerId = isset($argv[6]) ? (int) $argv[6] : 0;
$readyMarkerFile = isset($argv[7]) ? $argv[7] : '';
$releaseSignalFile = isset($argv[8]) ? $argv[8] : '';

if (! $userId || ! $courseId || ! $lessonId || ! $exerciseId || ! $profileId || ! $centerId || ! $readyMarkerFile || ! $releaseSignalFile) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments for concurrent_attempt_worker']);
    exit(1);
}

try {
    $service = app(ExerciseAttemptService::class);
    $user = User::findOrFail($userId);
    $course = Course::findOrFail($courseId);
    $lesson = Lesson::findOrFail($lessonId);
    $exercise = Exercise::findOrFail($exerciseId);
    $profile = StudentProfile::findOrFail($profileId);
    $center = Center::findOrFail($centerId);

    // 1. Signal Ready to Parent
    touch($readyMarkerFile);

    // 2. Await Release Signal from Parent
    $deadline = microtime(true) + 10.0;
    while (! file_exists($releaseSignalFile) && microtime(true) < $deadline) {
        usleep(10000); // 10ms
    }

    if (! file_exists($releaseSignalFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Timed out waiting for release signal']);
        exit(1);
    }

    // 3. Simultaneously invoke startOrResumeAttempt
    $attempt = $service->startOrResumeAttempt(
        $user,
        $course,
        $lesson,
        $exercise,
        $profile,
        $center
    );

    echo json_encode([
        'status' => 'success',
        'attempt_id' => $attempt->id,
        'attempt_status' => $attempt->status,
        'attempt_number' => $attempt->attempt_number,
    ]);
    exit(0);
} catch (ValidationException $e) {
    echo json_encode([
        'status' => 'validation_error',
        'message' => $e->getMessage(),
        'errors' => $e->errors(),
    ]);
    exit(0);
} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'exception',
        'message' => $e->getMessage(),
        'class' => get_class($e),
    ]);
    exit(1);
}
