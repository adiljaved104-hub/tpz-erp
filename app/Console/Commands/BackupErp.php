<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupRetentionService;
use App\Services\Backups\BackupSettingsService;
use App\Services\Backups\BackupStorageAccessException;
use App\Services\Backups\ErpBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class BackupErp extends Command
{
    protected $signature = 'erp:backup
        {--database-only : Back up only the configured database}
        {--storage-only : Back up only recoverable application storage}
        {--full : Require both database and recoverable storage regardless of UI settings}
        {--purpose=manual : scheduled, manual, deployment, or operational_reset}
        {--retention-dry-run : Show retention candidates without deleting them}';

    protected $description = 'Create a verified ERP database and storage backup with a manifest';

    public function handle(ErpBackupService $backup, BackupRetentionService $retention, BackupSettingsService $settingsService): int
    {
        if (($this->option('database-only') && $this->option('storage-only'))
            || ($this->option('full') && ($this->option('database-only') || $this->option('storage-only')))) {
            $this->error('Choose --database-only, --storage-only, or --full (mutually exclusive).');

            return self::INVALID;
        }

        try {
            $purpose = (string) $this->option('purpose');
            if (! in_array($purpose, ['scheduled', 'manual', 'deployment', 'operational_reset'], true)) {
                $this->error('Invalid --purpose; choose scheduled, manual, deployment, or operational_reset.');

                return self::INVALID;
            }
            $settings = $settingsService->applyToRuntimeConfig();
            $database = $this->option('full') || $this->option('database-only') || (! $this->option('storage-only') && (bool) $settings['database_enabled']);
            $storage = $this->option('full') || $this->option('storage-only') || (! $this->option('database-only') && (bool) $settings['storage_enabled']);
            $result = $backup->create(
                includeDatabase: $database,
                includeStorage: $storage,
                purpose: $purpose,
            );
            $retentionResult = $retention->prune((bool) $this->option('retention-dry-run'));

            $this->info('ERP backup completed successfully.');
            $this->line('Location: '.$result['path']);
            foreach ($result['manifest']['files'] as $file) {
                $this->line(sprintf('%s: %s · SHA-256 %s', ucfirst($file['kind']), $file['filename'], $file['sha256']));
            }
            $this->line(sprintf(
                '%s: %d eligible backup(s).',
                $this->option('retention-dry-run') ? 'Retention dry run' : 'Retention cleanup',
                count($retentionResult['deleted']),
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Log a safe category even when preflight fails before a manifest exists.
            // Exception messages may contain protected paths or database details.
            Log::error('ERP backup command failed.', [
                'exception_class' => $exception::class,
                'category' => $exception instanceof BackupStorageAccessException ? 'storage_unreadable' : 'other',
            ]);
            $this->error('ERP backup failed. Previous successful backups were not pruned. Review the application log for the safe diagnostic.');

            return self::FAILURE;
        }
    }
}
