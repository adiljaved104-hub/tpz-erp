<?php

namespace App\Services\Notifications;

use App\Enums\EmployeeRole;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Preferences\UserUiPreferenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class InventoryAlertPreferenceService
{
    public function __construct(
        private readonly UserUiPreferenceService $preferences,
        private readonly ActivityLogger $activity,
    ) {}

    public function canManage(User $user): bool
    {
        return $user->employee?->status === true
            && in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function enabled(User $user): bool
    {
        if (! $this->canManage($user)) {
            return true;
        }

        return $this->preferences->get($user, UserUiPreferenceService::INVENTORY_ALERTS, ['enabled']) !== ['disabled'];
    }

    public function set(User $user, bool $enabled): void
    {
        if (! $this->canManage($user)) {
            throw new AuthorizationException('Only active management users can change inventory alert preferences.');
        }

        DB::transaction(function () use ($user, $enabled): void {
            $previous = $this->enabled($user);
            $this->preferences->put($user, UserUiPreferenceService::INVENTORY_ALERTS, [$enabled ? 'enabled' : 'disabled']);
            if ($previous !== $enabled) {
                $this->activity->log('notifications.inventory_alert_preference_changed', $user, $user, [
                    'previous' => $previous,
                    'current' => $enabled,
                ]);
            }
        });
    }
}
