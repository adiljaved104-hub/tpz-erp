<?php

namespace App\Console\Commands;

use App\Services\Backups\BackupRetentionService;
use App\Services\Backups\BackupSettingsService;
use App\Services\Backups\ErpBackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupErp extends Command
{
    protected $signature = 'erp:backup
        {--database-only : Back up only the configured database}
        {--storage-only : Back up only recoverable application storage}
        {--retention-dry-run : Show retention candidates without deleting them}';

    protected $description = 'Create a verified ERP database and storage backup with a manifest';

    public function handle(ErpBackupService $backup, BackupRetentionService $retention, BackupSettingsService $settingsService): int
    {
        if ($this->option('database-only') && $this->option('storage-only')) {
            $this->error('Choose either --database-only or --storage-only, not both.');

            return self::INVALID;
        }

        try {
            $settings = $settingsService->applyToRuntimeConfig();
            $database = $this->option('database-only') || (! $this->option('storage-only') && (bool) $settings['database_enabled']);
            $storage = $this->option('storage-only') || (! $this->option('database-only') && (bool) $settings['storage_enabled']);
            $result = $backup->create(
                includeDatabase: $database,
                includeStorage: $storage,
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
        } catch (Throwable) {
            $this->error('ERP backup failed. Previous successful backups were not pruned. Review the application log for the safe diagnostic.');

            return self::FAILURE;
        }
    }
}
