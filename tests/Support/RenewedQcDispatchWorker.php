<?php

use App\Exceptions\InvalidOrderTransitionException;
use App\Models\Order;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\RenewedQcDispatchService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\SQLiteConnection;
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
    if (PHP_VERSION_ID < 80400) {
        Connection::resolverFor('sqlite', static function ($connection, $database, $prefix, $config) {
            $pdoResolver = static function () use ($database, $config) {
                $options = array_diff_key([
                    PDO::ATTR_CASE => PDO::CASE_NATURAL,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ], $config['options'] ?? []) + ($config['options'] ?? []);

                $pdo = new class('sqlite:'.$database, $options) extends PDO
                {
                    private bool $immediateTransaction = false;

                    public function __construct(string $dsn, array $options)
                    {
                        parent::__construct($dsn, null, null, $options);
                    }

                    public function exec(string $statement): int|false
                    {
                        $result = parent::exec($statement);

                        if (preg_match('/^\s*BEGIN\s+IMMEDIATE\b/i', $statement)) {
                            $this->immediateTransaction = true;
                        } elseif (preg_match('/^\s*(?:COMMIT|END)\b/i', $statement) || preg_match('/^\s*ROLLBACK\s*(?:;|$)/i', $statement)) {
                            $this->immediateTransaction = false;
                        }

                        return $result;
                    }

                    public function inTransaction(): bool
                    {
                        return $this->immediateTransaction || parent::inTransaction();
                    }

                    public function commit(): bool
                    {
                        return $this->immediateTransaction ? $this->exec('COMMIT') !== false : parent::commit();
                    }

                    public function rollBack(): bool
                    {
                        return $this->immediateTransaction ? $this->exec('ROLLBACK') !== false : parent::rollBack();
                    }
                };

                foreach ($config['pragmas'] ?? [] as $pragma => $value) {
                    $pdo->prepare("PRAGMA {$pragma} = {$value}")->execute();
                }

                if (isset($config['foreign_key_constraints'])) {
                    $pdo->prepare('PRAGMA foreign_keys = '.($config['foreign_key_constraints'] ? 1 : 0))->execute();
                }

                if (isset($config['busy_timeout'])) {
                    $pdo->prepare('PRAGMA busy_timeout = '.(int) $config['busy_timeout'])->execute();
                }

                if (isset($config['journal_mode'])) {
                    $pdo->prepare('PRAGMA journal_mode = '.(string) $config['journal_mode'])->execute();
                }

                if (isset($config['synchronous'])) {
                    $pdo->prepare('PRAGMA synchronous = '.(string) $config['synchronous'])->execute();
                }

                return $pdo;
            };

            return new class($pdoResolver, $database, $prefix, $config) extends SQLiteConnection
            {
                protected function executeBeginTransactionStatement()
                {
                    $this->getPdo()->exec('BEGIN IMMEDIATE TRANSACTION');
                }
            };
        });
    }
    $loader->setPsr4('App\\', $root.'/app');
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, 'App\\')) {
            $loader->addClassMap([$class => $root.'/app/'.str_replace('\\', '/', substr($class, 4)).'.php']);
        }
    }
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $resolved, 'database.connections.sqlite.url' => null]);
    // WAL + DEFERRED can cause SQLITE_BUSY_SNAPSHOT; PHP 8.3 ignores transaction_mode.
    // This disposable worker uses IMMEDIATE before snapshot reads; production connections stay unchanged.
    config(['database.connections.sqlite.transaction_mode' => 'IMMEDIATE']);
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
