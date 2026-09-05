<?php

namespace App\Services\Backups;

use App\Enums\BackupSettingsPermission;
use App\Models\BackupSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\BackupSettingsAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackupSettingsService
{
    public function __construct(
        private readonly BackupSettingsAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function stored(): ?BackupSetting
    {
        return Schema::hasTable('backup_settings') ? BackupSetting::query()->find(1) : null;
    }

    /** @return array<string, bool|int|string> */
    public function effective(): array
    {
        $settings = $this->stored();

        return [
            'source' => $settings ? 'Database settings' : 'Configuration fallback',
            'enabled' => $settings?->enabled ?? (bool) config('backup.schedule.enabled', false),
            'backup_time' => $settings?->backup_time ?? (string) config('backup.schedule.time', '02:00'),
            'database_enabled' => $settings?->database_enabled ?? (bool) config('backup.database.enabled', true),
            'storage_enabled' => $settings?->storage_enabled ?? (bool) config('backup.storage.enabled', true),
            'daily_retention' => $settings?->daily_retention ?? (int) config('backup.retention.daily', 14),
            'weekly_retention' => $settings?->weekly_retention ?? (int) config('backup.retention.weekly', 8),
            'monthly_retention' => $settings?->monthly_retention ?? (int) config('backup.retention.monthly', 6),
            'backup_disk' => $settings?->backup_disk ?? (string) config('backup.disk', 'local'),
            'offsite_enabled' => $settings?->offsite_enabled ?? (bool) config('backup.offsite.enabled', false),
            'encryption_enabled' => $settings?->encryption_enabled ?? (bool) config('backup.encryption.enabled', false),
        ];
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, User $actor): BackupSetting
    {
        $this->authorization->authorize($actor, BackupSettingsPermission::Manage);

        return DB::transaction(function () use ($data, $actor): BackupSetting {
            $settings = BackupSetting::query()->lockForUpdate()->find(1) ?? (new BackupSetting)->forceFill(['id' => 1]);
            $before = $settings->exists ? $this->auditableState($settings) : null;
            $settings->fill($data + ['updated_by_user_id' => $actor->id]);
            $settings->updated_by_user_id = $actor->id;
            $settings->save();

            $this->activity->log('backup_settings.updated', $actor, $settings, [
                'before' => $before,
                'after' => $this->auditableState($settings),
                'actor_id' => $actor->id,
            ]);

            return $settings->refresh();
        });
    }

    public function applyToRuntimeConfig(): array
    {
        $settings = $this->effective();
        config([
            'backup.schedule.enabled' => $settings['enabled'],
            'backup.schedule.time' => $settings['backup_time'],
            'backup.database.enabled' => $settings['database_enabled'],
            'backup.storage.enabled' => $settings['storage_enabled'],
            'backup.retention.daily' => $settings['daily_retention'],
            'backup.retention.weekly' => $settings['weekly_retention'],
            'backup.retention.monthly' => $settings['monthly_retention'],
            'backup.disk' => $settings['backup_disk'],
        ]);

        return $settings;
    }

    public function nextScheduledAt(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $settings = $this->effective();
        if (! $settings['enabled']) {
            return null;
        }

        $now ??= CarbonImmutable::now(config('app.timezone'));
        [$hour, $minute] = array_map('intval', explode(':', (string) $settings['backup_time']));
        $next = $now->setTime($hour, $minute);

        return $next->lessThanOrEqualTo($now) ? $next->addDay() : $next;
    }

    /** @return array<string, bool|int|string> */
    private function auditableState(BackupSetting $settings): array
    {
        return [
            'enabled' => $settings->enabled,
            'backup_time' => $settings->backup_time,
            'database_enabled' => $settings->database_enabled,
            'storage_enabled' => $settings->storage_enabled,
            'daily_retention' => $settings->daily_retention,
            'weekly_retention' => $settings->weekly_retention,
            'monthly_retention' => $settings->monthly_retention,
            'backup_disk' => $settings->backup_disk,
            'offsite_enabled' => $settings->offsite_enabled,
            'encryption_enabled' => $settings->encryption_enabled,
        ];
    }
}
