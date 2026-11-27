<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = $app->make('db')->connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'mysql'
    || $db->getDatabaseName() !== 'tpz_invoice_design_ci_test'
    || ! $db->getSchemaBuilder()->hasTable('migrations')) {
    throw new RuntimeException('Expected the pre-migrated disposable invoice design CI database.');
}
echo 'Invoice QA database: '.$db->getDatabaseName().'; MySQL '.$db->selectOne('SELECT VERSION() AS version')->version.PHP_EOL;
// CI applies migrations once to an empty disposable schema. Tests then roll back
// their own transactions without running migrate:fresh or touching ERP databases.
RefreshDatabaseState::$migrated = true;
$app->flush();
restore_error_handler();
restore_exception_handler();
