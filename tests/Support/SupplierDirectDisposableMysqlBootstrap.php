<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

// CI migrates two new disposable schemas before PHPUnit starts. Mark them as
// migrated so RefreshDatabase uses transactions without any destructive reset.
$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$db = $app->make('db')->connection();
if (! $app->environment('testing') || $db->getDriverName() !== 'mysql'
    || ! in_array($db->getDatabaseName(), [
        'tpz_supplier_direct_shipping_test',
        'tpz_supplier_direct_shipping_guard_test',
    ], true) || ! $db->getSchemaBuilder()->hasTable('migrations')) {
    throw new RuntimeException('Expected an already migrated disposable supplier-direct CI schema.');
}
RefreshDatabaseState::$migrated = true;
$app->flush();
restore_error_handler();
restore_exception_handler();
