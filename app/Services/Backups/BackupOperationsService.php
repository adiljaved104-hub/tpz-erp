<?php

namespace App\Services\Backups;

use App\Enums\BackupSettingsPermission;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\BackupSettingsAuthorization;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

class BackupOperationsService
{
    public function __construct(
        private readonly BackupSettingsAuthorization $authorization,
        private readonly BackupSettingsService $settings,
        private readonly ErpBackupService $backup,
        private readonly BackupRetentionService $retention,
        private readonly BackupHistoryService $history,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array<string, mixed> */
    public function runManual(User $actor): array
    {
        $this->authorization->authorize($actor, BackupSettingsPermission::Run);
        $lock = Cache::lock('erp-backup-operation', 3600);
        if (! $lock->get()) {
            throw new RuntimeException('A backup is already running.');
        }

        $subject = $this->settings->stored();

        try {
            $this->activity->log('backup.manual_started', $actor, $subject, ['actor_id' => $actor->id]);
            $settings = $this->settings->applyToRuntimeConfig();
            $result = $this->backup->create(
                includeDatabase: (bool) $settings['database_enabled'],
                includeStorage: (bool) $settings['storage_enabled'],
            );
            $retention = $this->retention->prune();
            $this->activity->log('backup.manual_completed', $actor, $subject, [
                'actor_id' => $actor->id,
                'backup_id' => basename((string) $result['path']),
                'components' => count((array) $result['manifest']['files']),
                'pruned' => count($retention['deleted']),
            ]);

            return $result;
        } catch (Throwable $exception) {
            $this->activity->log('backup.manual_failed', $actor, $subject, [
                'actor_id' => $actor->id,
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function verifyLatest(User $actor): array
    {
        $this->authorization->authorize($actor, BackupSettingsPermission::Verify);
        $latest = $this->history->latestSuccessful();
        if ($latest === null) {
            throw new RuntimeException('No successful backup is available to verify.');
        }

        return $this->verify((string) $latest['id'], $actor);
    }

    /** @return array<string, mixed> */
    public function verify(string $backupId, User $actor): array
    {
        $this->authorization->authorize($actor, BackupSettingsPermission::Verify);
        try {
            $result = $this->history->verify($backupId);
            $this->activity->log('backup.verification_completed', $actor, $this->settings->stored(), [
                'actor_id' => $actor->id,
                'backup_id' => $result['backup_id'],
                'database_integrity' => $result['database_integrity'],
                'foreign_key_violations' => $result['foreign_key_violations'],
            ]);
        } catch (Throwable $exception) {
            $this->activity->log('backup.verification_failed', $actor, $this->settings->stored(), [
                'actor_id' => $actor->id,
                'backup_id' => $backupId,
                'exception_class' => $exception::class,
            ]);
            throw $exception;
        }

        return $result;
    }
}
