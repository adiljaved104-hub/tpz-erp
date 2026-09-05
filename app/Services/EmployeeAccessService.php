<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class EmployeeAccessService
{
    public const INITIAL_OWNER_USER_ID = 1;

    public function canAccessPanel(User $user): bool
    {
        if (! Schema::hasTable('employees') || ! Schema::hasColumn('employees', 'user_id')) {
            return $user->getKey() === self::INITIAL_OWNER_USER_ID;
        }

        $employee = $user->employee;

        if ($employee !== null) {
            return app(CompanyEmailPolicyService::class)->allowsAuthentication($user);
        }

        if ($user->getKey() !== self::INITIAL_OWNER_USER_ID) {
            return false;
        }

        if (! Schema::hasTable('activity_logs')) {
            return true;
        }

        return ! ActivityLog::query()
            ->where('event', 'owner.bootstrap.completed')
            ->where('subject_type', $user->getMorphClass())
            ->where('subject_id', $user->getKey())
            ->exists();
    }
}
