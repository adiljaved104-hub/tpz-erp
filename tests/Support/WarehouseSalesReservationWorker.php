<?php

use App\Actions\Orders\SaveAndReserveOrder;
use App\DTOs\Orders\OrderItemData;
use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Models\ProductInventory;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

// Accept only a uniquely named disposable test database in the OS temp directory.
$phase = 'bootstrap';
try {
    if (count($argv) !== 6) {
        throw new RuntimeException('Invalid worker arguments.');
    }
    [$script, $path, $actorId, $inventoryId, $accountId, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-warehouse-sale-race-')) {
        throw new RuntimeException('Disposable database required.');
    }
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $resolved, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    $root = dirname(__DIR__, 2);
    $vendor = is_file($root.'/vendor/autoload.php') ? $root.'/vendor' : getenv('COMPOSER_VENDOR_DIR');
    $loader = require $vendor.'/autoload.php';
    $loader->setPsr4('App\\', $root.'/app');
    $map = [];
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, 'App\\')) {
            $map[$class] = $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php';
        }
    }
    $loader->addClassMap($map);
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // SQLite has no row locks: serialize writers at transaction start to model
    // the production lockForUpdate boundary without deferred read-lock upgrades.
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $resolved, 'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 10000, 'database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
    DB::purge('sqlite');
    Mail::fake();
    Notification::fake();
    Queue::fake();
    $actor = User::query()->findOrFail($actorId);
    $inventory = ProductInventory::query()->findOrFail($inventoryId);
    touch($resolved.'.ready.'.$slot);
    $deadline = microtime(true) + 20;
    while (! is_file($resolved.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        }
        usleep(10000);
    }
    try {
        $phase = 'order_reservation';
        app(SaveAndReserveOrder::class)->handle(new SaveAndReserveOrderData($inventory->warehouse_id, null, null, today()->toDateString(), $actor->employee->id, null, [new OrderItemData($inventory->product_id, 4, '250.00', allocationSources: [(int) $accountId => 4])], (string) Str::uuid()), $actor);
        echo json_encode(['result' => 'created']);
    } catch (ValidationException) {
        echo json_encode(['result' => 'rejected']);
    }
} catch (Throwable $exception) {
    // Never output SQL, credentials, full exception messages or environment values.
    fwrite(STDERR, json_encode(['phase' => $phase, 'error_class' => $exception::class, 'error_code' => (string) $exception->getCode(), 'driver_code' => $exception instanceof PDOException ? ($exception->errorInfo[1] ?? null) : null,
        'operation' => $exception instanceof QueryException ? strtok($exception->getSql(), ' ') : null,
    ]));
    exit(1);
}
