<?php

use App\Models\QcCertificate;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcStockTransferAssignmentService;
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
    if (count($argv) !== 6) {
        throw new RuntimeException('Invalid worker arguments.');
    }
    [$script, $path, $transferId, $certificateId, $actorId, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-qc-assignment-race-')
        || ! ctype_digit($transferId) || ! ctype_digit($certificateId) || ! ctype_digit($actorId) || ! in_array($slot, ['0', '1'], true)) {
        throw new RuntimeException('Disposable fixture required.');
    }

    $phase = 'bootstrap';
    $root = dirname(__DIR__, 2);
    foreach (['APP_BASE_PATH' => $root, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $resolved, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
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

    $transfer = StockTransfer::query()->findOrFail($transferId);
    $certificate = QcCertificate::query()->findOrFail($certificateId);
    $actor = User::query()->findOrFail($actorId);
    $code = app(QcDocumentService::class)->url($certificate);
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

    $phase = 'assign';
    try {
        app(QcStockTransferAssignmentService::class)->assignScan($transfer, $code, $actor);
        $outcome = 'assigned';
    } catch (ValidationException) {
        $outcome = 'rejected';
    } catch (QueryException $exception) {
        $message = $exception->getMessage();
        if (! str_contains($message, 'database is locked') && ! str_contains($message, 'qc_device_claims.active_device_id')) {
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
