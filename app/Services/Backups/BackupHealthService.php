<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

class BackupHealthService
{
    public function __construct(private readonly BackupSettingsService $settings) {}

    /** @return array{root: string, configured: bool, latest_at: ?CarbonImmutable, age_hours: ?int, checksums_valid: bool, manifest_path: ?string} */
    public function status(): array
    {
        $root = (string) config('backup.root');
        $latest = null;

        if (is_dir($root)) {
            foreach (File::directories($root) as $directory) {
                $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
                if (! is_file($manifestPath)) {
                    continue;
                }

                $manifest = json_decode((string) file_get_contents($manifestPath), true);
                if (! is_array($manifest) || ($manifest['status'] ?? null) !== 'successful') {
                    continue;
                }

                try {
                    $timestamp = CarbonImmutable::parse((string) $manifest['backup_timestamp']);
                } catch (\Throwable) {
                    continue;
                }

                if ($latest === null || $timestamp->greaterThan($latest['timestamp'])) {
                    $latest = compact('timestamp', 'manifest', 'manifestPath', 'directory');
                }
            }
        }

        $settings = $this->settings->effective();

        return [
            'root' => $root,
            'configured' => filled($root) && (is_dir($root) ? is_writable($root) : is_writable(dirname($root))),
            'latest_at' => $latest['timestamp'] ?? null,
            'age_hours' => isset($latest['timestamp']) ? (int) max(0, $latest['timestamp']->diffInHours(now(), true)) : null,
            'checksums_valid' => $latest !== null && $this->checksumsValid($latest['directory'], $latest['manifest']),
            'manifest_path' => $latest['manifestPath'] ?? null,
            'enabled' => (bool) $settings['enabled'],
            'database_enabled' => (bool) $settings['database_enabled'],
            'storage_enabled' => (bool) $settings['storage_enabled'],
            'backup_time' => (string) $settings['backup_time'],
            'backup_disk' => (string) $settings['backup_disk'],
            'daily_retention' => (int) $settings['daily_retention'],
            'weekly_retention' => (int) $settings['weekly_retention'],
            'monthly_retention' => (int) $settings['monthly_retention'],
            'offsite_enabled' => (bool) $settings['offsite_enabled'],
            'offsite_configured' => (bool) config('backup.offsite.configured', false),
            'encryption_enabled' => (bool) $settings['encryption_enabled'],
            'encryption_configured' => (bool) config('backup.encryption.configured', false),
            'next_at' => $this->settings->nextScheduledAt(),
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function checksumsValid(string $directory, array $manifest): bool
    {
        foreach ((array) ($manifest['files'] ?? []) as $file) {
            $path = $directory.DIRECTORY_SEPARATOR.($file['filename'] ?? '');
            if (! is_file($path) || ! hash_equals((string) ($file['sha256'] ?? ''), hash_file('sha256', $path))) {
                return false;
            }
        }

        return ((array) ($manifest['files'] ?? [])) !== [];
    }
}
