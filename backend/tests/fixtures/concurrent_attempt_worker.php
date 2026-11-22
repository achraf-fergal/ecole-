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

$dbName = getenv('ACCEPTANCE_DB_DATABASE') ?: 'school_saas_g5_acceptance_20261121_a7f3';
config(['database.default' => 'mysql']);
config(['database.connections.mysql.database' => $dbName]);
DB::purge('mysql');
DB::reconnect('mysql');

$userId = isset($argv[1]) ? (int) $argv[1] : 0;
$courseId = isset($argv[2]) ? (int) $argv[2] : 0;
$lessonId = isset($argv[3]) ? (int) $argv[3] : 0;
$exerciseId = isset($argv[4]) ? (int) $argv[4] : 0;
$profileId = isset($argv[5]) ? (int) $argv[5] : 0;
$centerId = isset($argv[6]) ? (int) $argv[6] : 0;
$barrierFile = isset($argv[7]) ? $argv[7] : '';

if (! $userId || ! $courseId || ! $lessonId || ! $exerciseId || ! $profileId || ! $centerId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments']);
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

    // Coordinate start using barrier file right before startOrResumeAttempt
    if (! empty($barrierFile)) {
        $waitLimit = 200; // max 10 seconds
        while (file_exists($barrierFile) && $waitLimit > 0) {
            usleep(25000); // 25ms
            $waitLimit--;
        }
    }

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
