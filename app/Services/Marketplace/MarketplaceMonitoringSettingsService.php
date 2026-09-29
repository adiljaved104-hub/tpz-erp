<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceMonitoringSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

class MarketplaceMonitoringSettingsService
{
    public function __construct(private readonly MarketplaceOperationsAuthorization $authorization, private readonly ActivityLogger $activity) {}

    /** @return array<string, mixed> */
    public function effective(): array
    {
        $fallback = [
            'monitoring_enabled' => (bool) config('marketplace_monitoring.enabled', true),
            'monitoring_interval_minutes' => (int) config('marketplace_monitoring.interval_minutes', 15),
            'employee_reminder_minutes' => 60,
            'acknowledgement_stops_reminders' => true,
            'escalation_threshold_minutes' => 1440,
            'escalation_recipient_strategy' => 'manager_owner_admin',
            'escalation_channels' => ['in_app', 'email'],
            'summary_times' => ['09:00', '14:00', '19:00'],
            'event_channels' => ['in_app', 'email'],
        ];
        try {
            if (! Schema::hasTable('marketplace_monitoring_settings')) {
                return $fallback;
            }
            $row = MarketplaceMonitoringSetting::query()->find(1);

            return $row instanceof MarketplaceMonitoringSetting ? array_replace($fallback, $row->only(array_keys($fallback))) : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @param array<string, mixed> $data */
    public function save(array $data, User $actor): MarketplaceMonitoringSetting
    {
        $this->authorization->authorize($actor, MarketplaceOperationsPermission::Manage);
        $summaryTimes = collect($data['summary_times'] ?? [])->map(fn ($time) => trim((string) $time))->filter(fn (string $time) => preg_match('/\A(?:[01]\d|2[0-3]):[0-5]\d\z/', $time) === 1)->unique()->sort()->values();
        if ($summaryTimes->isEmpty()) {
            throw ValidationException::withMessages(['summaryTimes' => 'Add at least one valid management summary time.']);
        }
        $channels = collect($data['escalation_channels'] ?? [])->intersect(['in_app', 'email'])->unique()->values();
        if ($channels->isEmpty()) {
            throw ValidationException::withMessages(['escalationChannels' => 'Enable at least one escalation channel.']);
        }

        return DB::transaction(function () use ($data, $actor, $summaryTimes, $channels): MarketplaceMonitoringSetting {
            $setting = MarketplaceMonitoringSetting::query()->lockForUpdate()->find(1) ?? new MarketplaceMonitoringSetting(['id' => 1]);
            $before = $setting->exists ? $setting->toArray() : [];
            $setting->forceFill([
                'id' => 1,
                'monitoring_enabled' => (bool) ($data['monitoring_enabled'] ?? true),
                'monitoring_interval_minutes' => max(5, min(1440, (int) ($data['monitoring_interval_minutes'] ?? 15))),
                'employee_reminder_minutes' => max(15, min(10080, (int) ($data['employee_reminder_minutes'] ?? 60))),
                'acknowledgement_stops_reminders' => (bool) ($data['acknowledgement_stops_reminders'] ?? true),
                'escalation_threshold_minutes' => max(15, min(43200, (int) ($data['escalation_threshold_minutes'] ?? 1440))),
                'escalation_recipient_strategy' => in_array($data['escalation_recipient_strategy'] ?? null, ['manager_owner_admin', 'owner_admin'], true) ? $data['escalation_recipient_strategy'] : 'manager_owner_admin',
                'escalation_channels' => $channels->all(),
                'summary_times' => $summaryTimes->all(),
                'event_channels' => collect($data['event_channels'] ?? ['in_app', 'email'])->intersect(['in_app', 'email'])->unique()->values()->all(),
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->activity->log('marketplace_monitoring.settings_updated', $actor, $setting, ['before' => $before, 'after' => $setting->fresh()->toArray()]);

            return $setting->refresh();
        });
    }

    public function summaryDueNow(): bool
    {
        return in_array(now()->format('H:i'), $this->effective()['summary_times'], true);
    }
}
