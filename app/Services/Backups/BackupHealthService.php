<?php

namespace App\Services\Backups;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Throwable;

class BackupHealthService
{
    public function __construct(private readonly BackupSettingsService $settings) {}

    /** @return array<string, mixed> */
    public function status(): array
    {
        $root = (string) config('backup.root');
        $latest = null;
        $unreadableManifests = 0;
        $inventoryScanFailed = false;

        if (is_dir($root)) {
            try {
                if (! is_readable($root) || (PHP_OS_FAMILY !== 'Windows' && ! is_executable($root))) {
                    $inventoryScanFailed = true;
                } else {
                    foreach (File::directories($root) as $directory) {
                        if (is_link($directory)) {
                            $unreadableManifests++;

                            continue;
                        }

                        $manifestPath = $directory.DIRECTORY_SEPARATOR.'manifest.json';
                        if (! is_file($manifestPath) && ! is_link($manifestPath)) {
                            continue;
                        }

                        $manifest = $this->readManifest($manifestPath);
                        if ($manifest === null) {
                            $unreadableManifests++;

                            continue;
                        }
                        if (($manifest['status'] ?? null) !== 'successful') {
                            continue;
                        }

                        try {
                            if (blank($manifest['backup_timestamp'] ?? null)) {
                                $unreadableManifests++;

                                continue;
                            }
                            $timestamp = CarbonImmutable::parse((string) $manifest['backup_timestamp']);
                        } catch (Throwable) {
                            $unreadableManifests++;

                            continue;
                        }

                        if ($latest === null || $timestamp->greaterThan($latest['timestamp'])) {
                            $latest = compact('timestamp', 'manifest', 'manifestPath', 'directory');
                        }
                    }
                }
            } catch (Throwable) {
                // An unreadable backup is an operational warning, not a page 500.
                $inventoryScanFailed = true;
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
            'access_warning' => $inventoryScanFailed || $unreadableManifests > 0,
            'unreadable_manifests' => $unreadableManifests,
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

    /** @return array<string, mixed>|null */
    private function readManifest(string $path): ?array
    {
        if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
            return null;
        }

        try {
            $contents = file_get_contents($path);
            $manifest = is_string($contents) ? json_decode($contents, true) : null;

            return is_array($manifest) ? $manifest : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $manifest */
    private function checksumsValid(string $directory, array $manifest): bool
    {
        $files = $manifest['files'] ?? null;
        if (! is_array($files) || $files === []) {
            return false;
        }

        foreach ($files as $file) {
            if (! is_array($file)) {
                return false;
            }

            $name = $file['filename'] ?? null;
            $expected = $file['sha256'] ?? null;
            if (! is_string($name) || $name === '' || basename($name) !== $name
                || ! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/i', $expected)) {
                return false;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$name;
            if (is_link($path) || ! is_file($path) || ! is_readable($path)) {
                return false;
            }

            try {
                $actual = hash_file('sha256', $path);
                if (! is_string($actual) || ! hash_equals(strtolower($expected), $actual)) {
                    return false;
                }
            } catch (Throwable) {
                return false;
            }
        }

        return true;
    }
}
