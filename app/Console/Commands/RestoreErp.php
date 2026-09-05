<?php

namespace App\Console\Commands;

use App\Services\Backups\ErpRestoreService;
use Illuminate\Console\Command;
use Throwable;

class RestoreErp extends Command
{
    protected $signature = 'erp:restore
        {backup : Path to a completed backup directory}
        {--target-database= : Disposable SQLite database target path}
        {--target-storage= : Disposable storage target directory}
        {--verify-only : Validate manifest and checksums without restoring}
        {--force : Allow replacement of an explicitly provided disposable target}';

    protected $description = 'Validate or restore an ERP backup to explicitly selected targets';

    public function handle(ErpRestoreService $restore): int
    {
        try {
            $manifest = $restore->validate((string) $this->argument('backup'));
            $this->info('Backup manifest and checksums are valid.');

            if ($this->option('verify-only')) {
                return self::SUCCESS;
            }

            if (! $this->option('target-database') && ! $this->option('target-storage')) {
                $this->error('No restore target was provided. Active database/storage restore is intentionally not implicit.');

                return self::INVALID;
            }

            $result = $restore->restoreToDisposableTargets(
                (string) $this->argument('backup'),
                $this->option('target-database') ?: null,
                $this->option('target-storage') ?: null,
                (bool) $this->option('force'),
            );

            if ($result['database'] !== null) {
                $this->line('Database integrity: '.$result['database']['integrity']);
                $this->line('Foreign-key violations: '.$result['database']['foreign_key_violations']);
            }
            $this->line('Storage files restored: '.$result['storage_files']);
            $this->info('Disposable restore completed successfully.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Restore rejected: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
