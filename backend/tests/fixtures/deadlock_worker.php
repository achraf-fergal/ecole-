<?php

require_once __DIR__.'/../../vendor/autoload.php';

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$dbName = getenv('ACCEPTANCE_DB_DATABASE') ?: 'school_saas_g5_acceptance_20261121_a7f3';
config(['database.default' => 'mysql']);
config(['database.connections.mysql.database' => $dbName]);
DB::purge('mysql');
DB::reconnect('mysql');

$rowA = (int) ($argv[1] ?? 0);
$rowB = (int) ($argv[2] ?? 0);
$step1File = $argv[3] ?? '';
$step2File = $argv[4] ?? '';

if (! $rowA || ! $rowB || ! $step1File || ! $step2File) {
    echo json_encode(['status' => 'error', 'message' => 'Missing arguments']);
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
    touch($step1File);

    // Wait until Parent has locked Row A and signaled Step 2
    $waitLimit = 200;
    while (! file_exists($step2File) && $waitLimit > 0) {
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
        'code' => $e->getCode(),
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
