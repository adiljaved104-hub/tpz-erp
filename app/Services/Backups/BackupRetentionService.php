<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BackupRetentionService
{
    /**
     * Only explicitly classified, unprotected *scheduled* backups can be
     * pruned. Legacy manifests have unknown intent: keep them fail-closed.
     *
     * @return array{kept: array<int, string>, expired: array<int, string>}
     */
    public function plan(?string $root = null): array
    {
        $root ??= (string) config('backup.root');
        $backups = $this->successfulBackups($root);

        if ($backups === []) {
            return ['kept' => [], 'expired' => []];
        }

        $scheduled = array_values(array_filter(
            $backups,
            fn (array $backup): bool => $backup['prunable'],
        ));

        // Manual, deployment, legacy, and any unknown or malformed manifests
        // are not eligible for automatic deletion.
        $keep = [];
        foreach ($backups as $backup) {
            if (! $backup['prunable']) {
                $keep[$backup['path']] = true;
            }
        }

        if ($scheduled !== []) {
            $keep[$scheduled[0]['path']] = true;
            $this->keepBuckets($scheduled, $keep, 'Y-m-d', (int) config('backup.retention.daily', 14));
            $this->keepBuckets($scheduled, $keep, 'o-W', (int) config('backup.retention.weekly', 8));
            $this->keepBuckets($scheduled, $keep, 'Y-m', (int) config('backup.retention.monthly', 6));
        }

        return [
            'kept' => array_keys($keep),
            'expired' => array_values(array_map(
                fn (array $backup): string => $backup['path'],
                array_filter($scheduled, fn (array $backup): bool => ! isset($keep[$backup['path']])),
            )),
        ];
    }

    /** @return array{kept: array<int, string>, deleted: array<int, string>} */
    public function prune(bool $dryRun = false, ?string $root = null): array
    {
        $root ??= (string) config('backup.root');
        $plan = $this->plan($root);

        if ($dryRun) {
            return ['kept' => $plan['kept'], 'deleted' => $plan['expired']];
        }

        $deleted = [];
        foreach ($plan['expired'] as $path) {
            // A second, immediately-before-delete check prevents a changed or
            // newly protected manifest from being erased using an old plan.
            if (! $this->eligibleForDeletion($root, $path)) {
                Log::warning('ERP retention skipped changed or protected backup.', [
                    'backup_id' => basename($path),
                ]);

                continue;
            }

            if (! File::deleteDirectory($path) || is_dir($path)) {
                throw new RuntimeException('Automatic retention could not safely delete the scheduled backup.');
            }

            $deleted[] = $path;
            Log::notice('ERP retention deleted a scheduled backup.', [
                'backup_id' => basename($path),
                'reason' => 'expired_scheduled_retention_bucket',
                'purpose' => 'scheduled',
            ]);
        }

        return ['kept' => $plan['kept'], 'deleted' => $deleted];
    }

    private function eligibleForDeletion(string $root, string $path): bool
    {
        $canonicalRoot = realpath($root);
        $canonicalPath = realpath($path);
        if ($canonicalRoot === false || $canonicalPath === false
            || ! is_dir($canonicalPath) || is_link($path)
            || dirname($canonicalPath) !== $canonicalRoot) {
            return false;
        }

        $manifest = $this->readManifest($canonicalPath);

        return $manifest !== null && $this->isPrunable($manifest);
    }

    /** @return array<int, array{path: string, timestamp: CarbonImmutable, prunable: bool}> */
    private function successfulBackups(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $backups = [];
        foreach (File::directories($root) as $directory) {
            if (is_link($directory)) {
                continue;
            }
            $manifest = $this->readManifest($directory);
            if ($manifest === null || ($manifest['status'] ?? null) !== 'successful'
                || blank($manifest['backup_timestamp'] ?? null)) {
                continue;
            }

            try {
                $backups[] = [
                    'path' => $directory,
                    'timestamp' => CarbonImmutable::parse($manifest['backup_timestamp']),
                    'prunable' => $this->isPrunable($manifest),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        usort($backups, fn (array $left, array $right): int =>
            $right['timestamp']->getTimestamp() <=> $left['timestamp']->getTimestamp());

        return $backups;
    }

    /** @return array<string, mixed>|null */
    private function readManifest(string $directory): ?array
    {
        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
        if (is_link($manifestPath) || ! is_file($manifestPath) || ! is_readable($manifestPath)) {
            return null;
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            return is_array($manifest) ? $manifest : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function isPrunable(array $manifest): bool
    {
        return ($manifest['status'] ?? null) === 'successful'
            && ($manifest['purpose'] ?? null) === 'scheduled'
            && ($manifest['retention_protected'] ?? null) === false;
    }

    /**
     * @param array<int, array{path: string, timestamp: CarbonImmutable, prunable: bool}> $backups
     * @param array<string, bool> $keep
     */
    private function keepBuckets(array $backups, array &$keep, string $format, int $limit): void
    {
        if ($limit === 0) {
            return;
        }

        $buckets = [];
        foreach ($backups as $backup) {
            $bucket = $backup['timestamp']->format($format);
            if (isset($buckets[$bucket])) {
                continue;
            }

            $buckets[$bucket] = true;
            $keep[$backup['path']] = true;

            if (count($buckets) >= $limit) {
                break;
            }
        }
    }
}
