<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

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
$connection = DB::connection('mysql_acceptance');
$tables = $connection->select('SHOW TABLES');
$tableNames = array_map(static fn (object $row): string => (string) array_values((array) $row)[0], $tables);
$migrationNames = in_array('migrations', $tableNames, true)
    ? $connection->table('migrations')->orderBy('batch')->pluck('migration')->all()
    : [];
$answerColumns = in_array('exercise_attempt_answers', $tableNames, true)
    ? array_map(static fn (object $row): string => $row->Field, $connection->select('SHOW COLUMNS FROM exercise_attempt_answers'))
    : [];
$version = $connection->selectOne('SELECT VERSION() AS version')->version;

echo json_encode([
    'database' => $database,
    'version' => $version,
    'migration_count' => count($migrationNames),
    'latest_migrations' => array_slice($migrationNames, -8),
    'exercise_answer_columns' => $answerColumns,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
