<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class ErpBackupService
{
    public const VERSION = 1;

    /** @return array<string, mixed> */
    public function create(bool $includeDatabase = true, bool $includeStorage = true): array
    {
        if (! $includeDatabase && ! $includeStorage) {
            throw new RuntimeException('At least one backup component must be selected.');
        }

        $root = (string) config('backup.root');
        $this->ensureTargetReady($root, $includeDatabase, $includeStorage);

        $timestamp = now();
        $name = $timestamp->format('Y-m-d_His');
        $temporary = $root.DIRECTORY_SEPARATOR.'.incomplete-'.$name.'-'.Str::lower(Str::random(8));
        $final = $root.DIRECTORY_SEPARATOR.$name;
        File::ensureDirectoryExists($temporary, 0700);

        try {
            $files = [];
            $driver = (string) DB::connection()->getDriverName();

            if ($includeDatabase) {
                $databaseFile = $driver === 'sqlite' ? 'database.sqlite' : 'database.sql';
                $destination = $temporary.DIRECTORY_SEPARATOR.$databaseFile;
                match ($driver) {
                    'sqlite' => $this->backupSqlite($destination),
                    'mysql', 'mariadb' => $this->backupMysql($destination),
                    default => throw new RuntimeException("Unsupported backup database driver: {$driver}"),
                };
                $files[] = $this->fileMetadata($destination, 'database');
            }

            if ($includeStorage) {
                $storageFile = $temporary.DIRECTORY_SEPARATOR.'storage.zip';
                $this->backupStorage($storageFile);
                $files[] = $this->fileMetadata($storageFile, 'storage');
            }

            $manifest = $this->manifest($timestamp->toImmutable(), $driver, $files, 'successful');
            $this->writeManifest($temporary, $manifest);

            if (is_dir($final)) {
                throw new RuntimeException('A backup already exists for this timestamp. Retry after one second.');
            }

            if (! rename($temporary, $final)) {
                throw new RuntimeException('Unable to finalize the backup directory atomically.');
            }

            return ['path' => $final, 'manifest' => $manifest];
        } catch (Throwable $exception) {
            $this->writeManifest($temporary, $this->manifest($timestamp->toImmutable(), (string) DB::connection()->getDriverName(), [], 'failed'));
            Log::error('ERP backup failed safely.', ['exception' => $exception::class, 'backup_path' => $temporary]);

            throw $exception;
        }
    }

    private function backupSqlite(string $destination): void
    {
        $source = $this->sqliteDatabasePath();
        if (! is_file($source)) {
            throw new RuntimeException('The configured SQLite database file does not exist.');
        }

        $pdo = new PDO('sqlite:'.$source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA busy_timeout = 10000');
        $pdo->exec('VACUUM INTO '.$pdo->quote($destination));

        $backup = new PDO('sqlite:'.$destination, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        if ($backup->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new RuntimeException('The SQLite backup failed its integrity check.');
        }

        if (count($backup->query('PRAGMA foreign_key_check')->fetchAll()) !== 0) {
            throw new RuntimeException('The SQLite backup contains foreign-key violations.');
        }
    }

    private function backupMysql(string $destination): void
    {
        $connection = (string) config('database.default');
        $configuration = (array) config("database.connections.{$connection}");
        $credentials = $this->temporaryMysqlClientFile($configuration);

        try {
            $command = [
                (string) config('backup.mysql.dump_binary', 'mysqldump'),
                '--defaults-extra-file='.$credentials,
                '--host='.(string) ($configuration['host'] ?? '127.0.0.1'),
                '--port='.(string) ($configuration['port'] ?? 3306),
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                '--no-tablespaces',
                '--result-file='.$destination,
                (string) ($configuration['database'] ?? ''),
            ];

            $process = new Process($command, base_path(), null, null, 3600);
            $process->run();
            if (! $process->isSuccessful() || ! is_file($destination) || filesize($destination) === 0) {
                throw new RuntimeException('The MySQL logical backup command failed.');
            }
        } finally {
            File::delete($credentials);
        }
    }

    private function backupStorage(string $destination): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required for storage backups.');
        }

        $zip = new ZipArchive;
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Unable to create the storage backup archive.');
        }

        try {
            foreach ((array) config('backup.storage.roots', []) as $prefix => $root) {
                if (! is_dir($root)) {
                    continue;
                }

                $archiveRoot = trim((string) $prefix, '/');
                if ($archiveRoot !== '' && ! $zip->addEmptyDir($archiveRoot)) {
                    throw new RuntimeException('Unable to add a configured storage root to the backup archive.');
                }

                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::LEAVES_ONLY,
                );

                foreach ($iterator as $file) {
                    if (! $file->isFile() || $file->isLink()) {
                        continue;
                    }

                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    if ($this->storagePathExcluded($relative)) {
                        continue;
                    }

                    if (! $zip->addFile($file->getPathname(), $archiveRoot.'/'.$relative)) {
                        throw new RuntimeException('Unable to add an eligible storage file to the backup archive.');
                    }
                }
            }
        } finally {
            $zip->close();
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            throw new RuntimeException('The storage backup archive was not created successfully.');
        }

        $verification = new ZipArchive;
        if ($verification->open($destination) !== true) {
            throw new RuntimeException('The storage backup archive could not be verified.');
        }
        $verification->close();
    }

    private function storagePathExcluded(string $relative): bool
    {
        $segments = explode('/', $relative);

        return in_array(basename($relative), (array) config('backup.storage.excluded_files', []), true)
            || array_intersect($segments, (array) config('backup.storage.excluded_segments', [])) !== [];
    }

    /** @param array<int, array<string, mixed>> $files */
    private function manifest(CarbonImmutable $timestamp, string $driver, array $files, string $status): array
    {
        return [
            'manifest_version' => self::VERSION,
            'status' => $status,
            'backup_timestamp' => $timestamp->setTimezone('UTC')->toIso8601String(),
            'application' => [
                'name' => (string) config('app.name'),
                'laravel_version' => app()->version(),
                'commit' => $this->commitHash(),
            ],
            'database_driver' => $driver,
            'latest_migration' => $this->latestMigration(),
            'files' => $files,
            'command' => ['name' => 'erp:backup', 'version' => self::VERSION],
        ];
    }

    /** @return array{kind: string, filename: string, bytes: int, sha256: string} */
    private function fileMetadata(string $path, string $kind): array
    {
        return [
            'kind' => $kind,
            'filename' => basename($path),
            'bytes' => (int) filesize($path),
            'sha256' => hash_file('sha256', $path),
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function writeManifest(string $directory, array $manifest): void
    {
        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL,
            LOCK_EX,
        );
    }

    private function ensureTargetReady(string $root, bool $includeDatabase, bool $includeStorage): void
    {
        File::ensureDirectoryExists($root, 0700);
        if (! is_writable($root)) {
            throw new RuntimeException('The configured backup location is not writable.');
        }

        $required = 20 * 1024 * 1024;
        if ($includeDatabase && DB::connection()->getDriverName() === 'sqlite') {
            $required += (int) filesize($this->sqliteDatabasePath());
        }
        if ($includeStorage) {
            foreach ((array) config('backup.storage.roots', []) as $storageRoot) {
                $required += $this->directorySize((string) $storageRoot);
            }
        }

        $free = disk_free_space($root);
        if ($free !== false && $free < $required) {
            throw new RuntimeException('Insufficient free disk space for a safe backup.');
        }
    }

    private function directorySize(string $root): int
    {
        if (! is_dir($root)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    private function sqliteDatabasePath(): string
    {
        $connection = (string) config('database.default');
        $path = (string) config("database.connections.{$connection}.database");

        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : base_path($path);
    }

    /** @param array<string, mixed> $configuration */
    private function temporaryMysqlClientFile(array $configuration): string
    {
        $path = storage_path('framework/backup-mysql-'.Str::uuid().'.cnf');
        $escape = fn (mixed $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
        file_put_contents($path, "[client]\nuser=".$escape($configuration['username'] ?? '')."\npassword=".$escape($configuration['password'] ?? '')."\n", LOCK_EX);
        @chmod($path, 0600);

        return $path;
    }

    private function latestMigration(): ?string
    {
        try {
            return DB::table('migrations')->orderByDesc('batch')->orderByDesc('id')->value('migration');
        } catch (Throwable) {
            return null;
        }
    }

    private function commitHash(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--verify', 'HEAD'], base_path(), null, null, 5);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
