<?php

use App\Models\OrderItem;
use App\Models\User;
use App\Services\Qc\QcOrderAssignmentService;
use Illuminate\Contracts\Console\Kernel;
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
    [$script, $path, $itemId, $certificateId, $actorId, $slot] = $argv;
    $resolved = realpath($path);
    if (! $resolved || dirname($resolved) !== realpath(sys_get_temp_dir()) || ! str_starts_with(basename($resolved), 'tpz-qc-assignment-race-')
        || ! ctype_digit($itemId) || ! ctype_digit($certificateId) || ! ctype_digit($actorId) || ! in_array($slot, ['0', '1'], true)) {
        throw new RuntimeException('Disposable fixture required.');
    }
    $phase = 'bootstrap';
    $root = dirname(__DIR__, 2);
    foreach (['APP_BASE_PATH' => $root, 'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $resolved, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync'] as $key => $value) {
        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv($key.'='.$value);
    }
    $loader = require $root.'/vendor/autoload.php';
    // Also supports the project's isolated-worktree shared-vendor test pattern.
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
    $item = OrderItem::query()->findOrFail($itemId);
    $actor = User::query()->findOrFail($actorId);
    $phase = 'ready';
    if (! touch($resolved.'.ready.'.$slot)) {
        throw new RuntimeException('Readiness failed.');
    }
    $deadline = microtime(true) + 20;
    while (! is_file($resolved.'.go')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Barrier timeout.');
        } usleep(10000);
    }
    $phase = 'assign';
    try {
        $assignment = app(QcOrderAssignmentService::class)->assign($item, (int) $certificateId, $actor);
        echo json_encode(['outcome' => 'assigned', 'assignment_id' => $assignment->id]);
    } catch (ValidationException) {
        echo json_encode(['outcome' => 'rejected']);
    }
} catch (Throwable $exception) {
    // Never expose SQL, exception messages, credentials, environment or file paths.
    fwrite(STDERR, json_encode(['phase' => $phase, 'error_class' => $exception::class, 'error_code' => (string) $exception->getCode()], JSON_THROW_ON_ERROR));
    exit(1);
}
