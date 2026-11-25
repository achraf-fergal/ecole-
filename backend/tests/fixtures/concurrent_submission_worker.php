<?php

namespace Tests\Fixtures;

use App\Models\ExerciseAttempt;
use App\Services\Learning\ExerciseAttemptService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require_once __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$host = getenv('ACCEPTANCE_DB_HOST');
$port = getenv('ACCEPTANCE_DB_PORT') ?: '3306';
$database = getenv('ACCEPTANCE_DB_DATABASE');
$username = getenv('ACCEPTANCE_DB_USERNAME');
$password = getenv('ACCEPTANCE_DB_PASSWORD') !== false ? getenv('ACCEPTANCE_DB_PASSWORD') : '';

if (! $host || ! $database || ! $username) {
    echo json_encode(['status' => 'error', 'message' => 'Missing dedicated ACCEPTANCE_DB_* variables.']);
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
        'engine' => 'InnoDB',
    ],
    'database.default' => 'mysql_acceptance',
]);

DB::purge('mysql_acceptance');
DB::setDefaultConnection('mysql_acceptance');

$attemptId = isset($argv[1]) ? (int) $argv[1] : 0;
$selectedOptionId = isset($argv[2]) ? (int) $argv[2] : 0;
$readyMarkerFile = $argv[3] ?? '';
$releaseSignalFile = $argv[4] ?? '';

if (! $attemptId || ! $selectedOptionId || ! $readyMarkerFile || ! $releaseSignalFile) {
    echo json_encode(['status' => 'error', 'message' => 'Missing concurrent submission worker arguments.']);
    exit(1);
}

try {
    $attempt = ExerciseAttempt::query()->with('answers')->findOrFail($attemptId);
    $answer = $attempt->answers->sole();
    $service = app(ExerciseAttemptService::class);

    touch($readyMarkerFile);

    $deadline = microtime(true) + 10.0;
    while (! file_exists($releaseSignalFile) && microtime(true) < $deadline) {
        usleep(10000);
    }

    if (! file_exists($releaseSignalFile)) {
        echo json_encode(['status' => 'error', 'message' => 'Timed out waiting for the concurrent submission barrier.']);
        exit(1);
    }

    $graded = $service->submitAttempt($attempt, [[
        'question_id' => (int) $answer->question_id,
        'selected_option_id' => $selectedOptionId,
    ]]);

    echo json_encode([
        'status' => 'success',
        'attempt_id' => (int) $graded->id,
        'attempt_number' => (int) $graded->attempt_number,
        'score' => (float) $graded->score,
        'max_score' => (float) $graded->max_score,
        'percentage' => (float) $graded->percentage,
        'passed' => (bool) $graded->passed,
        'submitted_at' => $graded->submitted_at?->toIso8601String(),
        'graded_at' => $graded->graded_at?->toIso8601String(),
        'selected_option_id' => (int) $graded->answers->sole()->selected_option_id,
        'is_correct' => (bool) $graded->answers->sole()->is_correct,
        'points_awarded' => (float) $graded->answers->sole()->points_awarded,
    ]);
    exit(0);
} catch (HttpExceptionInterface $exception) {
    if ($exception->getStatusCode() === 409) {
        echo json_encode(['status' => 'conflict', 'http_status' => 409]);
        exit(0);
    }

    echo json_encode([
        'status' => 'http_error',
        'http_status' => $exception->getStatusCode(),
        'message' => $exception->getMessage(),
    ]);
    exit(1);
} catch (\Throwable $exception) {
    echo json_encode([
        'status' => 'error',
        'message' => $exception->getMessage(),
        'class' => get_class($exception),
    ]);
    exit(1);
}
