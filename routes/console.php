<?php

use App\Services\Backups\BackupSettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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

$marketplaceInterval = max(5, (int) config('marketplace_monitoring.interval_minutes', 15));
Schedule::command('marketplace:monitor')
    ->cron("*/{$marketplaceInterval} * * * *")
    ->name('marketplace-operations-monitor')
    ->withoutOverlapping(max(10, $marketplaceInterval * 2))
    ->when(fn (): bool => (bool) config('marketplace_monitoring.enabled', true));

Schedule::command('marketplace:send-summary')
    ->dailyAt((string) config('marketplace_monitoring.summary_time', '08:00'))
    ->name('marketplace-operations-summary')
    ->withoutOverlapping(60)
    ->when(fn (): bool => (bool) config('marketplace_monitoring.enabled', true));

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
