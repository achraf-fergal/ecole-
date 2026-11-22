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

$dbName = getenv('ACCEPTANCE_DB_DATABASE') ?: 'school_saas_g5_acceptance_20261121_a7f3';
config(['database.default' => 'mysql']);
config(['database.connections.mysql.database' => $dbName]);
DB::purge('mysql');
DB::reconnect('mysql');

$lessonId = isset($argv[1]) ? (int) $argv[1] : 0;
$userId = isset($argv[2]) ? (int) $argv[2] : 0;
$barrierFile = isset($argv[3]) ? $argv[3] : '';

if (! $lessonId || ! $userId) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments']);
    exit(1);
}

try {
    $service = app(LessonRevisionService::class);
    $lesson = Lesson::findOrFail($lessonId);
    $user = User::findOrFail($userId);

    // Coordinate start using barrier file right before publishing
    if (! empty($barrierFile)) {
        $waitLimit = 200; // max 10 seconds (50ms interval)
        while (file_exists($barrierFile) && $waitLimit > 0) {
            usleep(25000); // 25ms
            $waitLimit--;
        }
    }

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
