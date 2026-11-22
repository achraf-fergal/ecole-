<?php

require_once __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
        'message' => 'Missing ACCEPTANCE_DB_* environment variables for deadlock worker.',
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

$rowA = (int) ($argv[1] ?? 0);
$rowB = (int) ($argv[2] ?? 0);
$workerLockedBFile = $argv[3] ?? '';
$parentLockedAFile = $argv[4] ?? '';

if (! $rowA || ! $rowB || ! $workerLockedBFile || ! $parentLockedAFile) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments for deadlock_worker']);
    exit(1);
}

try {
    $pdo = DB::connection()->getPdo();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 5');

    $pdo->beginTransaction();

    // 1. Lock Row B
    $stmt = $pdo->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
    $stmt->execute([$rowB]);
    $stmt->fetch();

    // Signal Step 1 complete (Row B locked)
    touch($workerLockedBFile);

    // Wait until Parent has locked Row A and signaled Step 2
    $waitLimit = 200;
    while (! file_exists($parentLockedAFile) && $waitLimit > 0) {
        usleep(25000);
        $waitLimit--;
    }

    // Small delay to let parent issue its blocking request for Row B
    usleep(150000);

    // 2. Attempt to lock Row A -> Causes Deadlock Cycle!
    $stmt = $pdo->prepare('SELECT id FROM lessons WHERE id = ? FOR UPDATE');
    $stmt->execute([$rowA]);
    $stmt->fetch();

    $pdo->commit();
    echo json_encode(['status' => 'success']);
    exit(0);
} catch (\PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'status' => 'pdo_exception',
        'sqlstate' => $e->getCode(),
        'error_info' => $e->errorInfo ?? null,
        'message' => $e->getMessage(),
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
