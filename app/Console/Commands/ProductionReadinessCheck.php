<?php

namespace App\Console\Commands;

use App\Models\EmailSetting;
use App\Services\Backups\BackupHealthService;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Operations\BackgroundServiceHealth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductionReadinessCheck extends Command
{
    protected $signature = 'erp:production-check';

    protected $description = 'Run read-only production environment readiness checks without exposing secrets';

    /** @var array<int, array{string, string, string}> */
    private array $results = [];

    public function handle(EmailConfigurationService $email, Schedule $schedule, BackgroundServiceHealth $backgroundHealth, BackupHealthService $backupHealth): int
    {
        $production = app()->environment('production');
        $url = (string) config('app.url');
        $driver = (string) config('database.connections.'.config('database.default').'.driver');

        $this->record($production ? 'PASS' : 'WARN', 'Application environment', (string) config('app.env'));
        $this->record(! config('app.debug') ? 'PASS' : ($production ? 'FAIL' : 'WARN'), 'Debug mode', config('app.debug') ? 'enabled' : 'disabled');
        $this->record(filled(config('app.key')) ? 'PASS' : 'FAIL', 'Application key', filled(config('app.key')) ? 'configured' : 'missing');
        $this->record(str_starts_with($url, 'https://') ? 'PASS' : ($production ? 'FAIL' : 'WARN'), 'Application URL', $this->safeUrl($url));
        $this->record(config('app.timezone') === 'UTC' ? 'PASS' : 'WARN', 'Application timezone', (string) config('app.timezone'));

        $this->record($driver === 'mysql' ? 'PASS' : 'WARN', 'Database driver', $driver);
        if ($driver === 'mysql') {
            $connection = (string) config('database.default');
            $this->record(config("database.connections.{$connection}.strict") === true ? 'PASS' : 'FAIL', 'MySQL strict mode', config("database.connections.{$connection}.strict") ? 'enabled' : 'disabled');
            $this->record(config("database.connections.{$connection}.charset") === 'utf8mb4' ? 'PASS' : 'FAIL', 'MySQL charset', (string) config("database.connections.{$connection}.charset"));
            $this->mysqlRuntimeChecks();
        }

        $this->record(in_array(config('session.driver'), ['database', 'redis'], true) ? 'PASS' : 'WARN', 'Session driver', (string) config('session.driver'));
        $this->record(config('session.http_only') === true ? 'PASS' : 'FAIL', 'Session HttpOnly', config('session.http_only') ? 'enabled' : 'disabled');
        $this->record(config('session.secure') === true ? 'PASS' : ($production ? 'FAIL' : 'WARN'), 'Secure session cookie', config('session.secure') ? 'enabled' : 'disabled');
        $this->record(in_array(config('session.same_site'), ['lax', 'strict'], true) ? 'PASS' : 'WARN', 'Session SameSite', (string) config('session.same_site'));
        $this->record((int) config('session.lifetime') <= 480 ? 'PASS' : 'WARN', 'Session lifetime', (int) config('session.lifetime').' minutes');

        $this->record(! in_array(config('cache.default'), ['array', 'null'], true) ? 'PASS' : 'WARN', 'Cache store', (string) config('cache.default'));
        $this->record(config('queue.default') !== 'sync' ? 'PASS' : ($production ? 'FAIL' : 'WARN'), 'Queue connection', (string) config('queue.default'));
        $this->queueHealthChecks($production);
        $this->emailCheck($email);
        $this->storageChecks();
        $this->backupHealthChecks($backupHealth, $production);
        $this->migrationCheck();
        $this->schedulerHealthChecks($schedule, $backgroundHealth, $production);
        $trustedProxies = config('trustedproxy.proxies');
        $this->record(filled($trustedProxies) ? 'PASS' : 'WARN', 'Reverse proxy trust', filled($trustedProxies) ? 'explicit proxy addresses configured' : 'must be configured for the exact production proxy network before deployment');

        $this->table(['Result', 'Check', 'Detail'], $this->results);

        return collect($this->results)->contains(fn (array $result): bool => $result[0] === 'FAIL')
            ? self::FAILURE
            : self::SUCCESS;
    }

    private function backupHealthChecks(BackupHealthService $health, bool $production): void
    {
        $status = $health->status();
        $this->record(
            $status['enabled'] ? 'PASS' : ($production ? 'FAIL' : 'WARN'),
            'Automatic backups',
            $status['enabled']
                ? 'enabled at '.$status['backup_time'].'; '.collect([$status['database_enabled'] ? 'database' : null, $status['storage_enabled'] ? 'storage' : null])->filter()->join(' + ')
                : 'disabled by effective Backup Settings',
        );
        $this->record('PASS', 'Backup retention', sprintf(
            '%d daily · %d weekly · %d monthly; newest valid backup protected',
            $status['daily_retention'],
            $status['weekly_retention'],
            $status['monthly_retention'],
        ));
        $this->record(
            $status['offsite_enabled'] && ! $status['offsite_configured'] ? 'FAIL' : 'PASS',
            'Offsite backup readiness',
            $status['offsite_configured'] ? ($status['offsite_enabled'] ? 'configured and enabled' : 'configured, disabled') : 'not configured',
        );
        $this->record(
            $status['encryption_enabled'] && ! $status['encryption_configured'] ? 'FAIL' : 'PASS',
            'Backup encryption readiness',
            $status['encryption_configured'] ? ($status['encryption_enabled'] ? 'configured and enabled' : 'configured, disabled') : 'not configured',
        );
        $this->record(
            $status['configured'] ? 'PASS' : ($production ? 'FAIL' : 'WARN'),
            'Backup location',
            $status['configured'] ? $this->relativePath($status['root']) : 'not configured or not writable',
        );

        if ($status['latest_at'] === null) {
            $this->record($production ? 'FAIL' : 'WARN', 'Latest successful backup', 'none found');

            return;
        }

        $maximumAge = (int) config('backup.health.maximum_age_hours', 26);
        $healthy = $status['age_hours'] <= $maximumAge && $status['checksums_valid'];
        $detail = $status['latest_at']->utc()->format('Y-m-d H:i:s').' UTC · '.$status['age_hours'].'h old · checksums '.($status['checksums_valid'] ? 'valid' : 'invalid');
        $this->record($healthy ? 'PASS' : ($production ? 'FAIL' : 'WARN'), 'Latest successful backup', $detail);
    }

    private function mysqlRuntimeChecks(): void
    {
        try {
            $runtime = DB::selectOne('SELECT @@default_storage_engine AS engine, @@session.time_zone AS session_timezone');
            $this->record(strtolower((string) $runtime->engine) === 'innodb' ? 'PASS' : 'FAIL', 'MySQL storage engine', (string) $runtime->engine);
            $this->record(in_array((string) $runtime->session_timezone, ['+00:00', 'UTC'], true) ? 'PASS' : 'WARN', 'MySQL session timezone', (string) $runtime->session_timezone);
        } catch (Throwable) {
            $this->record('FAIL', 'MySQL runtime', 'connection/runtime settings could not be verified');
        }
    }

    private function emailCheck(EmailConfigurationService $email): void
    {
        try {
            if (EmailSetting::query()->exists()) {
                $this->record($email->enabled() ? 'PASS' : 'WARN', 'Email delivery', $email->enabled() ? 'enabled through database settings' : 'database settings present but email delivery disabled');

                return;
            }

            $this->record('WARN', 'Email delivery', 'database settings absent; environment fallback is '.config('mail.default'));
        } catch (Throwable) {
            $this->record('FAIL', 'Email delivery', 'configuration could not be verified');
        }
    }

    private function queueHealthChecks(bool $production): void
    {
        if (config('queue.default') !== 'database') {
            $this->record('WARN', 'Queue backlog', 'health inspection is implemented for the approved database queue');

            return;
        }

        try {
            if (! Schema::hasTable('jobs') || ! Schema::hasTable('failed_jobs')) {
                $this->record('FAIL', 'Queue infrastructure', 'jobs or failed_jobs table is missing');

                return;
            }

            $pending = (int) DB::table('jobs')->count();
            $oldestCreatedAt = DB::table('jobs')->min('created_at');
            $oldestAge = is_numeric($oldestCreatedAt) ? max(0, now()->timestamp - (int) $oldestCreatedAt) : null;
            $staleAfter = max(60, (int) config('queue.health.stale_after', 600));

            if ($pending === 0) {
                $this->record('PASS', 'Queue backlog', '0 pending; no stale queued work detected');
            } elseif ($oldestAge !== null && $oldestAge > $staleAfter) {
                $this->record($production ? 'FAIL' : 'WARN', 'Queue backlog', "{$pending} pending; oldest is ".$this->formatAge($oldestAge));
            } else {
                $this->record('PASS', 'Queue backlog', "{$pending} pending; oldest is ".$this->formatAge($oldestAge ?? 0));
            }

            $failed = (int) DB::table('failed_jobs')->count();
            $this->record($failed === 0 ? 'PASS' : 'WARN', 'Failed queue jobs', $failed === 0 ? 'none' : "{$failed} require operator review");
        } catch (Throwable) {
            $this->record('FAIL', 'Queue infrastructure', 'queue health could not be inspected');
        }
    }

    private function schedulerHealthChecks(Schedule $schedule, BackgroundServiceHealth $health, bool $production): void
    {
        $this->record(count($schedule->events()) >= 4 ? 'PASS' : 'WARN', 'Scheduler registrations', count($schedule->events()).' schedules registered');
        $this->recordBackgroundTimestamp(
            'Scheduler heartbeat',
            $health->last(BackgroundServiceHealth::SCHEDULER_HEARTBEAT),
            180,
            $production,
        );
        $this->recordBackgroundTimestamp(
            'Task evaluator',
            $health->last(BackgroundServiceHealth::TASK_EVALUATOR_SUCCESS),
            5400,
            false,
        );
        $this->recordBackgroundTimestamp(
            'Warranty evaluator',
            $health->last(BackgroundServiceHealth::WARRANTY_EVALUATOR_SUCCESS),
            10800,
            false,
        );

        if (! config('hikvision.enabled') || ! config('hikvision.scheduled_sync_enabled')) {
            $this->record('PASS', 'Hikvision scheduler', 'disabled by configuration; no device work is expected');

            return;
        }

        $interval = max(1, (int) config('hikvision.sync_interval_minutes', 5));
        $this->recordBackgroundTimestamp('Hikvision sync attempt', $health->last(BackgroundServiceHealth::HIKVISION_ATTEMPT), $interval * 180, $production);
        $this->recordBackgroundTimestamp('Hikvision sync success', $health->last(BackgroundServiceHealth::HIKVISION_SUCCESS), $interval * 360, false);
    }

    private function recordBackgroundTimestamp(string $check, ?CarbonImmutable $timestamp, int $staleAfterSeconds, bool $failWhenStale): void
    {
        if ($timestamp === null) {
            $this->record($failWhenStale ? 'FAIL' : 'WARN', $check, 'no execution timestamp recorded');

            return;
        }

        $age = max(0, now()->timestamp - $timestamp->timestamp);
        $this->record(
            $age <= $staleAfterSeconds ? 'PASS' : ($failWhenStale ? 'FAIL' : 'WARN'),
            $check,
            $timestamp->utc()->format('Y-m-d H:i:s').' UTC · '.$this->formatAge($age).' ago',
        );
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.'s';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m';
        }

        return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
    }

    private function storageChecks(): void
    {
        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            $this->record(is_dir($path) && is_writable($path) ? 'PASS' : 'FAIL', 'Writable path', $this->relativePath($path));
        }

        $publicLink = public_path('storage');
        $linked = is_dir($publicLink) && realpath($publicLink) === realpath(storage_path('app/public'));
        $this->record($linked ? 'PASS' : 'WARN', 'Public storage link', $linked ? 'configured' : 'missing; required for public Company Profile assets');
    }

    private function migrationCheck(): void
    {
        try {
            if (! Schema::hasTable('migrations')) {
                $this->record('FAIL', 'Migrations', 'migration repository is missing');

                return;
            }

            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = collect(File::files(database_path('migrations')))
                ->map(fn ($file): string => $file->getFilenameWithoutExtension())
                ->all();
            $pending = array_values(array_diff($files, $ran));
            $this->record($pending === [] ? 'PASS' : 'FAIL', 'Pending migrations', $pending === [] ? 'none' : count($pending).' pending');
        } catch (Throwable) {
            $this->record('FAIL', 'Migrations', 'status could not be verified');
        }
    }

    private function record(string $status, string $check, string $detail): void
    {
        $this->results[] = [$status, $check, $detail];
    }

    private function safeUrl(string $url): string
    {
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : 'invalid';
    }

    private function relativePath(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }
}
