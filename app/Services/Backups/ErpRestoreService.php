<?php

namespace App\Services\Backups;

use Illuminate\Support\Facades\File;
use PDO;
use RuntimeException;
use ZipArchive;

class ErpRestoreService
{
    /** @return array<string, mixed> */
    public function validate(string $backupDirectory): array
    {
        $directory = realpath($backupDirectory);
        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('The selected backup directory does not exist.');
        }

        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('The selected backup has no manifest.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['status'] ?? null) !== 'successful') {
            throw new RuntimeException('Only a completed successful backup can be restored.');
        }
        if ((int) ($manifest['manifest_version'] ?? 0) !== ErpBackupService::VERSION) {
            throw new RuntimeException('The backup manifest version is not supported.');
        }

        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $filename = basename((string) ($file['filename'] ?? ''));
            $path = $directory.DIRECTORY_SEPARATOR.$filename;
            if ($filename === '' || ! is_file($path)) {
                throw new RuntimeException('A required backup component is missing.');
            }
            if (! hash_equals((string) ($file['sha256'] ?? ''), hash_file('sha256', $path))) {
                throw new RuntimeException("Backup checksum validation failed for {$filename}.");
            }
        }

        return $manifest + ['_directory' => $directory];
    }

    /** @return array{database: ?array<string, mixed>, storage_files: int, latest_migration: ?string} */
    public function restoreToDisposableTargets(string $backupDirectory, ?string $targetDatabase, ?string $targetStorage, bool $overwrite = false): array
    {
        $manifest = $this->validate($backupDirectory);
        $directory = (string) $manifest['_directory'];
        $databaseResult = null;
        $storageFiles = 0;

        if ($targetDatabase !== null) {
            if (($manifest['database_driver'] ?? null) !== 'sqlite') {
                throw new RuntimeException('A non-SQLite backup cannot be restored to a SQLite file target.');
            }

            $databaseFile = $this->componentPath($directory, $manifest, 'database');
            $databaseResult = $this->restoreSqlite($databaseFile, $targetDatabase, $overwrite);
        }

        if ($targetStorage !== null) {
            $storageFile = $this->componentPath($directory, $manifest, 'storage');
            $storageFiles = $this->restoreStorage($storageFile, $targetStorage, $overwrite);
        }

        if ($targetDatabase === null && $targetStorage === null) {
            throw new RuntimeException('Specify a disposable database and/or storage target.');
        }

        return [
            'database' => $databaseResult,
            'storage_files' => $storageFiles,
            'latest_migration' => $manifest['latest_migration'] ?? null,
        ];
    }

    /** @return array{integrity: string, foreign_key_violations: int, migration_count: int, latest_migration: ?string} */
    private function restoreSqlite(string $source, string $target, bool $overwrite): array
    {
        $target = $this->absolutePath($target);
        $active = $this->absolutePath((string) config('database.connections.'.config('database.default').'.database'));
        if ($this->samePath($target, $active)) {
            throw new RuntimeException('Active database restore is intentionally disabled; rehearse to a disposable target and follow the approved maintenance runbook.');
        }

        File::ensureDirectoryExists(dirname($target));
        if (is_file($target) && ! $overwrite) {
            throw new RuntimeException('The target database already exists; use --force only for a disposable target you intend to replace.');
        }

        $temporary = $target.'.restoring';
        File::delete($temporary);
        if (! copy($source, $temporary)) {
            throw new RuntimeException('Unable to copy the restored SQLite database.');
        }

        $pdo = new PDO('sqlite:'.$temporary, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        $violations = count($pdo->query('PRAGMA foreign_key_check')->fetchAll());
        if ($integrity !== 'ok' || $violations !== 0) {
            File::delete($temporary);
            throw new RuntimeException('The restored SQLite database failed integrity verification.');
        }

        $migrationCount = (int) $pdo->query('SELECT COUNT(*) FROM migrations')->fetchColumn();
        $latestMigration = $pdo->query('SELECT migration FROM migrations ORDER BY batch DESC, id DESC LIMIT 1')->fetchColumn() ?: null;
        unset($pdo);
        if (is_file($target)) {
            File::delete($target);
        }
        if (! rename($temporary, $target)) {
            File::delete($temporary);
            throw new RuntimeException('Unable to finalize the restored SQLite database.');
        }

        return [
            'integrity' => $integrity,
            'foreign_key_violations' => $violations,
            'migration_count' => $migrationCount,
            'latest_migration' => $latestMigration,
        ];
    }

    private function restoreStorage(string $archive, string $target, bool $overwrite): int
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP Zip extension is required for storage restore.');
        }

        $target = $this->absolutePath($target);
        if ($this->samePath($target, storage_path('app'))) {
            throw new RuntimeException('Active storage restore is intentionally disabled; use a disposable target and follow the approved maintenance runbook.');
        }
        if (is_dir($target) && $this->directoryHasFiles($target) && ! $overwrite) {
            throw new RuntimeException('The storage target is not empty; use --force only for a disposable target you intend to replace.');
        }
        File::ensureDirectoryExists($target);

        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('The storage archive cannot be opened.');
        }

        $restored = 0;
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = str_replace('\\', '/', (string) $zip->getNameIndex($index));
                if ($name === '' || str_starts_with($name, '/') || str_contains($name, '../')) {
                    throw new RuntimeException('The storage archive contains an unsafe path.');
                }

                $destination = $target.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
                File::ensureDirectoryExists(dirname($destination));
                $input = $zip->getStream($name);
                $output = fopen($destination, 'wb');
                if ($input === false || $output === false) {
                    throw new RuntimeException('A storage file could not be restored.');
                }
                stream_copy_to_stream($input, $output);
                fclose($input);
                fclose($output);
                $restored++;
            }
        } finally {
            $zip->close();
        }

        return $restored;
    }

    /** @param array<string, mixed> $manifest */
    private function componentPath(string $directory, array $manifest, string $kind): string
    {
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            if (($file['kind'] ?? null) === $kind) {
                return $directory.DIRECTORY_SEPARATOR.basename((string) $file['filename']);
            }
        }

        throw new RuntimeException("The backup does not contain a {$kind} component.");
    }

    private function absolutePath(string $path): string
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:[\\\\\/]/', $path)
            ? $path
            : base_path($path);
    }

    private function samePath(string $left, string $right): bool
    {
        $normalize = function (string $path): string {
            $resolved = realpath($path);

            return strtolower(rtrim(str_replace('\\', '/', $resolved !== false ? $resolved : $path), '/'));
        };

        return $normalize($left) === $normalize($right);
    }

    private function directoryHasFiles(string $directory): bool
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                return true;
            }
        }

        return false;
    }
}
