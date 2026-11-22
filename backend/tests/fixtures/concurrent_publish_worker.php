<?php

require_once __DIR__.'/../../vendor/autoload.php';

use App\Models\Lesson;
use App\Models\User;
use App\Services\Learning\LessonRevisionService;
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

$lessonId = isset($argv[1]) ? (int) $argv[1] : 0;
$userId = isset($argv[2]) ? (int) $argv[2] : 0;
$readyMarkerFile = isset($argv[3]) ? $argv[3] : '';
$releaseSignalFile = isset($argv[4]) ? $argv[4] : '';

if (! $lessonId || ! $userId || ! $readyMarkerFile || ! $releaseSignalFile) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments for concurrent_publish_worker']);
    exit(1);
}

try {
    $service = app(LessonRevisionService::class);
    $lesson = Lesson::findOrFail($lessonId);
    $user = User::findOrFail($userId);

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

    // 3. Simultaneously invoke real publication service
    $published = $service->publish($lesson, $user);

    echo json_encode([
        'status' => 'success',
        'revision_id' => $published->id,
        'version_number' => $published->version_number,
    ]);
    exit(0);
} catch (ValidationException $e) {
    echo json_encode([
        'status' => 'validation_error',
        'message' => $e->getMessage(),
        'errors' => $e->errors(),
    ]);
    exit(0); // Exit cleanly with validation payload for parent inspection
} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'exception',
        'message' => $e->getMessage(),
        'class' => get_class($e),
    ]);
    exit(1);
}
