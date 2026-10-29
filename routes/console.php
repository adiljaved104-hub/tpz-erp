<?php

use App\Models\CustomerReturnExceptionCase;
use App\Services\Backups\BackupSettingsService;
use App\Services\Marketplace\MarketplaceMonitoringSettingsService;
use App\Services\Returns\CustomerReturnExceptionHoldService;
use App\Services\Returns\CustomerReturnVideoUploadService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('returns:prune-video-uploads', function (CustomerReturnVideoUploadService $uploads) {
    $this->info('Expired temporary return video uploads removed: '.$uploads->pruneExpired());
})->purpose('Remove expired private return-video chunks; permanent evidence is never deleted.');

Artisan::command('returns:recover-exception-holds', function (CustomerReturnExceptionHoldService $holds) {
    $ids = CustomerReturnExceptionCase::query()->whereNull('resolved_at')
        ->where('protection_status', 'assessment_pending')->orderBy('id')->limit(100)->pluck('id');
    foreach ($ids as $id) {
        $case = CustomerReturnExceptionCase::query()->with('item.customerReturn', 'finding.recordedBy')->find($id);
        $actor = $case?->finding?->recordedBy;
        if ($actor === null) {
            // No fabricated actor: leave the case visibly pending for manual investigation.
            $this->warn('Return exception '.$id.' has no recorded actor; protection remains unverified.');

            continue;
        }
        $holds->assess($id, $actor);
    }
    $this->info('Return exception hold assessments checked: '.$ids->count());
})->purpose('Recover interrupted inventory protection assessments for Return exceptions.');

Schedule::command('returns:recover-exception-holds')->everyMinute()
    ->name('return-exception-hold-recovery')->withoutOverlapping(5);

Schedule::command('returns:prune-video-uploads')->hourly()
    ->name('return-video-upload-cleanup')->withoutOverlapping(10);

Schedule::command('erp:scheduler-heartbeat')
    ->everyMinute()
    ->name('erp-scheduler-heartbeat')
    ->withoutOverlapping(5);

Schedule::command('tasks:send-due-notifications')
    ->everyThirtyMinutes()
    ->name('task-due-notification-evaluator')
    ->withoutOverlapping(10);

Schedule::command('warranty:send-sla-notifications')
    ->hourly()
    ->name('warranty-sla-notification-evaluator')
    ->withoutOverlapping(30);

Schedule::command('inventory:send-stock-reminders')
    ->hourly()
    ->name('inventory-stock-reminder-evaluator')
    ->withoutOverlapping(30);

$marketplaceInterval = max(5, (int) app(MarketplaceMonitoringSettingsService::class)->effective()['monitoring_interval_minutes']);
Schedule::command('marketplace:monitor')
    ->cron("*/{$marketplaceInterval} * * * *")
    ->name('marketplace-operations-monitor')
    ->withoutOverlapping(max(10, $marketplaceInterval * 2))
    ->when(fn (): bool => (bool) app(MarketplaceMonitoringSettingsService::class)->effective()['monitoring_enabled']);

Schedule::command('marketplace:send-summary --scheduled')
    ->everyMinute()
    ->name('marketplace-operations-summary')
    ->withoutOverlapping(60)
    ->when(fn (): bool => (bool) app(MarketplaceMonitoringSettingsService::class)->effective()['monitoring_enabled']);

$hikvisionInterval = max(1, (int) config('hikvision.sync_interval_minutes', 5));
Schedule::command('hikvision:sync-attendance')
    ->cron("*/{$hikvisionInterval} * * * *")
    ->name('hikvision-attendance-sync')
    ->withoutOverlapping(max(10, $hikvisionInterval * 2));

$backupSettings = app(BackupSettingsService::class)->effective();
Schedule::command('erp:backup')
    ->dailyAt((string) $backupSettings['backup_time'])
    ->name('erp-database-storage-backup')
    ->withoutOverlapping((int) config('backup.schedule.overlap_minutes', 180))
    ->when(fn (): bool => (bool) app(BackupSettingsService::class)->effective()['enabled']);
