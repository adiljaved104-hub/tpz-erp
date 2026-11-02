<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use FilesystemIterator;
use PDO;
use RuntimeException;
use Throwable;

class BackupHistoryService
{
    public function __construct(private readonly ErpRestoreService $restore) {}

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        $root = (string) config('backup.root');
        if (! is_dir($root)) {
            return [];
        }

        $history = [];
        try {
            if (! is_readable($root) || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($root))) {
                return [];
            }

            // Unlike File::directories(), FilesystemIterator includes hidden
            // .incomplete-* directories that contain failed backup manifests.
            foreach (new FilesystemIterator($root, FilesystemIterator::SKIP_DOTS) as $entry) {
                if ($entry->isLink() || ! $entry->isDir()) {
                    continue;
                }

                $directory = $entry->getPathname();
                $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
                if (is_link($manifestPath) || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
                    continue;
                }

                try {
                    $contents = file_get_contents($manifestPath);
                    $manifest = is_string($contents) ? json_decode($contents, true) : null;
                    if (! is_array($manifest) || blank($manifest['backup_timestamp'] ?? null)) {
                        continue;
                    }

                    $timestamp = CarbonImmutable::parse((string) $manifest['backup_timestamp']);
                } catch (Throwable) {
                    continue;
                }

                $files = array_values(array_filter(
                    is_array($manifest['files'] ?? null) ? $manifest['files'] : [],
                    static fn (mixed $file): bool => is_array($file),
                ));
                $status = $manifest['status'] ?? null;
                $driver = $manifest['database_driver'] ?? null;
                $history[] = [
                    'id' => basename($directory),
                    'timestamp' => $timestamp,
                    'status' => is_string($status) ? $status : 'unknown',
                    'database' => collect($files)->contains(fn (array $file): bool => ($file['kind'] ?? null) === 'database'),
                    'storage' => collect($files)->contains(fn (array $file): bool => ($file['kind'] ?? null) === 'storage'),
                    'bytes' => (int) collect($files)->sum(fn (array $file): int => (int) ($file['bytes'] ?? 0)),
                    'driver' => is_string($driver) ? $driver : '—',
                ];
            }
        } catch (Throwable) {
            // Preserve readable rows if enumeration stops unexpectedly.
        }

        usort($history, fn (array $left, array $right): int => $right['timestamp']->timestamp <=> $left['timestamp']->timestamp);

        return $history;
    }

    /** @return array<string, mixed>|null */
    public function latestSuccessful(): ?array
    {
        return collect($this->all())->first(fn (array $backup): bool => $backup['status'] === 'successful');
    }

    /** @return array{backup_id: string, timestamp: CarbonImmutable, database_integrity: string, foreign_key_violations: int} */
    public function verify(string $backupId): array
    {
        if (! preg_match('/^[A-Za-z0-9._-]+$/', $backupId)) {
            throw new RuntimeException('The selected backup identifier is invalid.');
        }

        $root = realpath((string) config('backup.root'));
        $directory = realpath((string) config('backup.root').DIRECTORY_SEPARATOR.$backupId);
        if ($root === false || $directory === false || ! str_starts_with(strtolower(str_replace('\\', '/', $directory)).'/', strtolower(str_replace('\\', '/', $root)).'/')) {
            throw new RuntimeException('The selected backup is outside the configured backup location.');
        }

        $manifest = $this->restore->validate($directory);
        $integrity = 'not applicable';
        $violations = 0;

        if (($manifest['database_driver'] ?? null) === 'sqlite') {
            $database = collect((array) ($manifest['files'] ?? []))->firstWhere('kind', 'database');
            if (is_array($database)) {
                $path = $directory.DIRECTORY_SEPARATOR.basename((string) $database['filename']);
                $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
                $violations = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
                unset($pdo);
                if ($integrity !== 'ok' || $violations !== 0) {
                    throw new RuntimeException('The backup database failed integrity verification.');
                }
            }
        }

        return [
            'backup_id' => $backupId,
            'timestamp' => CarbonImmutable::parse((string) $manifest['backup_timestamp']),
            'database_integrity' => $integrity,
            'foreign_key_violations' => $violations,
        ];
    }
}
