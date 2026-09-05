<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

class BackupRetentionService
{
    /** @return array{kept: array<int, string>, expired: array<int, string>} */
    public function plan(?string $root = null): array
    {
        $root ??= (string) config('backup.root');
        $backups = $this->successfulBackups($root);

        if ($backups === []) {
            return ['kept' => [], 'expired' => []];
        }

        $keep = [$backups[0]['path'] => true];
        $this->keepBuckets($backups, $keep, 'Y-m-d', (int) config('backup.retention.daily', 14));
        $this->keepBuckets($backups, $keep, 'o-W', (int) config('backup.retention.weekly', 8));
        $this->keepBuckets($backups, $keep, 'Y-m', (int) config('backup.retention.monthly', 6));

        return [
            'kept' => array_keys($keep),
            'expired' => array_values(array_map(
                fn (array $backup): string => $backup['path'],
                array_filter($backups, fn (array $backup): bool => ! isset($keep[$backup['path']])),
            )),
        ];
    }

    /** @return array{kept: array<int, string>, deleted: array<int, string>} */
    public function prune(bool $dryRun = false, ?string $root = null): array
    {
        $plan = $this->plan($root);

        if (! $dryRun) {
            foreach ($plan['expired'] as $path) {
                File::deleteDirectory($path);
            }
        }

        return ['kept' => $plan['kept'], 'deleted' => $plan['expired']];
    }

    /** @return array<int, array{path: string, timestamp: CarbonImmutable}> */
    private function successfulBackups(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $backups = [];
        foreach (File::directories($root) as $directory) {
            $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
            if (! is_file($manifestPath)) {
                continue;
            }

            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            if (! is_array($manifest) || ($manifest['status'] ?? null) !== 'successful' || blank($manifest['backup_timestamp'] ?? null)) {
                continue;
            }

            try {
                $backups[] = [
                    'path' => $directory,
                    'timestamp' => CarbonImmutable::parse($manifest['backup_timestamp']),
                ];
            } catch (\Throwable) {
                continue;
            }
        }

        usort($backups, fn (array $left, array $right): int => $right['timestamp']->getTimestamp() <=> $left['timestamp']->getTimestamp());

        return $backups;
    }

    /**
     * @param  array<int, array{path: string, timestamp: CarbonImmutable}>  $backups
     * @param  array<string, bool>  $keep
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
