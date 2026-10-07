<?php

use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\RenewedQcDispatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// Accept only an explicitly named disposable SQLite fixture in the OS temp directory.
$phase = 'input';
try {
    if (count($argv) !== 7) {
        throw new RuntimeException('Invalid worker arguments.');
    }
    [$script, $path, $operation, $orderId, $value, $actorId, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-qc-dispatch-race-')
        || ! ctype_digit($orderId) || ! ctype_digit($actorId) || ! in_array($slot, ['0', '1'], true) || ! in_array($operation, ['scan', 'ship', 'reopen'], true)) {
        throw new RuntimeException('Disposable fixture required.');
    }
    $phase = 'bootstrap';
    $root = dirname(__DIR__, 2);
    foreach (['APP_BASE_PATH' => $root, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $resolved, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32))] as $key => $setting) {
        $_ENV[$key] = $_SERVER[$key] = $setting;
        putenv($key.'='.$setting);
    }
    $loader = require $root.'/vendor/autoload.php';
    $loader->setPsr4('App\\', $root.'/app');
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, 'App\\')) {
            $loader->addClassMap([$class => $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php']);
        }
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $resolved, 'database.connections.sqlite.url' => null]);
    DB::purge('sqlite');
    DB::statement('PRAGMA busy_timeout=10000');
    Mail::fake();
    Notification::fake();
    Queue::fake();
    $actor = User::query()->findOrFail($actorId);
    $order = Order::query()->findOrFail($orderId);
    $phase = 'ready';
    if (! touch($resolved.'.ready.'.$slot)) {
        throw new RuntimeException('Readiness failed.');
    }
    $deadline = microtime(true) + 20;
    while (! is_file($resolved.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        }
        usleep(10000);
    }
    $phase = $operation;
    try {
        $service = app(RenewedQcDispatchService::class);
        match ($operation) {
            'scan' => $service->scan($order, $order->items->sole()->id, $value, $actor),
            'ship' => $service->ship($order, $value, $actor),
            'reopen' => app(QcInspectionService::class)->reopen(QcInspection::query()->findOrFail($value), 'Concurrent recheck', $actor),
        };
        echo json_encode(['outcome' => match ($operation) {
            'scan' => 'assigned', 'ship' => 'shipped', 'reopen' => 'reopened'
        }]);
    } catch (ValidationException|InvalidOrderTransitionException) {
        echo json_encode(['outcome' => 'rejected']);
    }
} catch (Throwable $e) {
    fwrite(STDERR, json_encode(['phase' => $phase, 'error_class' => $e::class, 'error_code' => (string) $e->getCode()], JSON_THROW_ON_ERROR));
    exit(1);
}
