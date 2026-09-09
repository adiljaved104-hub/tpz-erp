<?php

use App\Models\Quotation;
use App\Models\User;
use App\Services\Quotations\QuotationConversionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

// Subprocess fixture for the competing-conversion regression; never accepts an active database.
$slot = $argv[4] ?? 'unknown';
$phase = 'input_validation';

try {
    if (count($argv) !== 5) {
        throw new RuntimeException('Worker arguments are invalid.');
    }

    [$script, $path, $quotationId, $actorId, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-source-race-')) {
        throw new RuntimeException('A disposable test database is required.');
    }

    $phase = 'application_bootstrap';
    putenv('APP_ENV=testing');
    putenv('DB_CONNECTION=sqlite');
    putenv('DB_DATABASE='.$resolved);
    putenv('DB_URL=');
    putenv('CACHE_STORE=array');
    putenv('SESSION_DRIVER=array');
    putenv('MAIL_MAILER=array');
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $resolved, 'database.connections.sqlite.url' => null]);
    DB::purge('sqlite');
    Mail::fake();
    Notification::fake();
    Queue::fake();

    $phase = 'fixture_lookup';
    $quote = Quotation::query()->findOrFail($quotationId);
    $actor = User::query()->findOrFail($actorId);

    $phase = 'readiness_signal';
    if (! touch($resolved.'.ready.'.$slot)) {
        throw new RuntimeException('Worker readiness signal could not be created.');
    }

    $phase = 'barrier_wait';
    $deadline = microtime(true) + 20;
    while (! is_file($resolved.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Test barrier timed out.');
        }
        usleep(10000);
    }

    $phase = 'quotation_conversion';
    $order = app(QuotationConversionService::class)->toOrder($quote, (int) $quote->warehouse_id, $actor);
    echo json_encode(['order_id' => $order->id]);
} catch (Throwable $exception) {
    // Intentionally omit exception messages, SQL, paths, environment and configuration.
    fwrite(STDERR, json_encode([
        'worker' => (string) $slot,
        'phase' => $phase,
        'error_class' => $exception::class,
        'error_code' => (string) $exception->getCode(),
        'driver_code' => $exception instanceof PDOException ? ($exception->errorInfo[1] ?? null) : null,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
