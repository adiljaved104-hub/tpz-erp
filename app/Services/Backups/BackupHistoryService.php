<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;

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
        foreach (File::directories($root) as $directory) {
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest)) {
                continue;
            }

            try {
                $timestamp = CarbonImmutable::parse((string) ($manifest['backup_timestamp'] ?? ''));
            } catch (\Throwable) {
                continue;
            }

            $files = (array) ($manifest['files'] ?? []);
            $history[] = [
                'id' => basename($directory),
                'timestamp' => $timestamp,
                'status' => (string) ($manifest['status'] ?? 'unknown'),
                'database' => collect($files)->contains(fn (array $file): bool => ($file['kind'] ?? null) === 'database'),
                'storage' => collect($files)->contains(fn (array $file): bool => ($file['kind'] ?? null) === 'storage'),
                'bytes' => (int) collect($files)->sum(fn (array $file): int => (int) ($file['bytes'] ?? 0)),
                'driver' => (string) ($manifest['database_driver'] ?? '—'),
            ];
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
