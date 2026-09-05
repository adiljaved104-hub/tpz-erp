<?php

namespace App\Services;

use App\Enums\AuthSecurityPermission;
use App\Enums\EmployeeRole;
use App\Models\User;
use App\Services\Authorization\AuthSecurityAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TwoFactorService
{
    public function __construct(
        private readonly AuthSecurityAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function enableSelf(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $locked->forceFill(['email_two_factor_enabled_at' => now()])->save();
            $this->activity->log('auth_security.two_factor_enabled', $user, $locked, ['actor_id' => $user->id]);
        });
        $user->refresh();
    }

    public function disableSelf(User $user): void
    {
        $this->disable($user, $user, false);
    }

    public function disableForManagement(User $target, User $actor): void
    {
        $this->authorization->authorize($actor, AuthSecurityPermission::ManageTwoFactor);

        if ($target->employee?->role === EmployeeRole::Owner && $actor->employee?->role !== EmployeeRole::Owner) {
            throw new AuthorizationException;
        }

        $this->disable($target, $actor, true);
    }

    private function disable(User $target, User $actor, bool $managementReset): void
    {
        DB::transaction(function () use ($target, $actor, $managementReset): void {
            $locked = User::query()->lockForUpdate()->findOrFail($target->id);
            $locked->forceFill(['email_two_factor_enabled_at' => null])->save();
            $this->activity->log(
                $managementReset ? 'auth_security.two_factor_admin_disabled' : 'auth_security.two_factor_disabled',
                $actor,
                $locked,
                ['actor_id' => $actor->id],
            );
        });
        $target->refresh();
    }
}
