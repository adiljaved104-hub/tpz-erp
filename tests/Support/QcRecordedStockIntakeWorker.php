<?php

use App\Models\User;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcRecordedRenewedStockIntakeService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

// Disposable SQLite concurrency fixture only; never accepts an active ERP database.
$phase = 'input';
try {
    if (count($argv) !== 7) {
        throw new RuntimeException('Invalid worker arguments.');
    }

    [$script, $path, $intakeId, $actorId, $serial, $idempotencyKey, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-qc-intake-race-')
        || ! ctype_digit($intakeId) || ! ctype_digit($actorId) || ! preg_match('/^INTAKE-RACE-[01]$/', $serial)
        || ! preg_match('/^[0-9a-f-]{36}$/i', $idempotencyKey) || ! in_array($slot, ['0', '1'], true)) {
        throw new RuntimeException('Disposable fixture required.');
    }

    $phase = 'bootstrap';
    $root = dirname(__DIR__, 2);
    foreach ([
        'APP_BASE_PATH' => $root,
        'APP_ENV' => 'testing',
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)),
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $resolved,
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ] as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
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
    $intake = app(QcRecordedRenewedStockIntakeService::class)->findForStart((int) $intakeId, $actor);
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

    $phase = 'start';
    try {
        app(QcInspectionService::class)->start([
            'product_id' => $intake->product_id,
            'warehouse_id' => $intake->warehouse_id,
            'recorded_stock_intake_id' => $intake->id,
            'serial' => $serial,
            'features' => [],
            'idempotency_key' => $idempotencyKey,
        ], $actor);
        $outcome = 'started';
    } catch (ValidationException) {
        $outcome = 'rejected';
    } catch (QueryException $exception) {
        if (! str_contains(strtolower($exception->getMessage()), 'database is locked')) {
            throw $exception;
        }
        $outcome = 'rejected';
    }

    echo json_encode(['outcome' => $outcome], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    // Never expose SQL, exception messages, credentials, environment or file paths.
    fwrite(STDERR, json_encode(['phase' => $phase, 'error_class' => $exception::class, 'error_code' => (string) $exception->getCode()], JSON_THROW_ON_ERROR));
    exit(1);
}
