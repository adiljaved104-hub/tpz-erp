<?php

namespace App\Services\Qc;

use App\Enums\EmployeeRole;
use App\Models\QcExceptionSetting;
use App\Models\User;
use App\Services\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QcExceptionSettingsService
{
    public function stored(): ?QcExceptionSetting
    {
        return Schema::hasTable('qc_exception_settings') ? QcExceptionSetting::query()->find(1) : null;
    }

    public function status(): array
    {
        $setting = $this->stored();
        if ($setting === null || ! $setting->enabled || $setting->duration_mode === 'disabled') {
            return ['enabled' => false, 'mode' => 'disabled', 'expires_at' => null, 'status' => 'Inactive'];
        }
        if ($setting->expires_at !== null && $setting->expires_at->isPast()) {
            return ['enabled' => true, 'mode' => $setting->duration_mode, 'expires_at' => $setting->expires_at, 'status' => 'Expired'];
        }

        return ['enabled' => true, 'mode' => $setting->duration_mode, 'expires_at' => $setting->expires_at, 'status' => 'Active'];
    }

    public function active(): bool
    {
        $status = $this->status();

        return $status['enabled'] && $status['status'] === 'Active';
    }

    public function ownerCanManage(?User $actor): bool
    {
        return $actor?->employee?->role === EmployeeRole::Owner && $actor->employee->status === true;
    }

    public function description(): string
    {
        $status = $this->status();
        $expiry = $status['expires_at']?->format('Y-m-d H:i').' (server time)' ?? 'Until manually disabled';

        return 'Status: '.$status['status'].' · Expiry: '.$expiry.'. Changes are Owner-only and audited.';
    }

    public function formState(): array
    {
        $setting = $this->stored();

        return [
            'enabled' => (bool) $setting?->enabled,
            'duration_mode' => $setting?->duration_mode ?? 'disabled',
            'custom_expiry' => $setting?->expires_at?->format('Y-m-d H:i'),
        ];
    }

    public function save(array $input, User $actor): QcExceptionSetting
    {
        if (! $this->ownerCanManage($actor)) {
            throw new AuthorizationException('Only the Owner may manage the Renewed QC exception activation.');
        }

        $data = Validator::make($input, [
            'enabled' => ['required', 'boolean'],
            'duration_mode' => ['required', 'in:disabled,one_day,seven_days,thirty_days,custom,unlimited'],
            'custom_expiry' => ['nullable', 'date', 'after:now'],
        ])->validate();
        if ($data['enabled'] && $data['duration_mode'] === 'disabled') {
            throw ValidationException::withMessages(['duration_mode' => 'Choose an activation duration.']);
        }
        if ($data['enabled'] && $data['duration_mode'] === 'custom' && empty($data['custom_expiry'])) {
            throw ValidationException::withMessages(['custom_expiry' => 'Choose a future expiry time.']);
        }

        $expiresAt = match ($data['duration_mode']) {
            'one_day' => CarbonImmutable::now()->addDay(),
            'seven_days' => CarbonImmutable::now()->addDays(7),
            'thirty_days' => CarbonImmutable::now()->addDays(30),
            'custom' => CarbonImmutable::parse($data['custom_expiry']),
            default => null,
        };

        return DB::transaction(function () use ($actor, $data, $expiresAt): QcExceptionSetting {
            $setting = QcExceptionSetting::query()->lockForUpdate()->find(1) ?? new QcExceptionSetting;
            $before = ['enabled' => (bool) $setting->enabled, 'duration_mode' => $setting->duration_mode, 'expires_at' => $setting->expires_at?->toIso8601String()];
            $setting->forceFill(['id' => 1, 'enabled' => (bool) $data['enabled'], 'duration_mode' => $data['enabled'] ? $data['duration_mode'] : 'disabled', 'expires_at' => $data['enabled'] ? $expiresAt : null, 'updated_by_user_id' => $actor->id])->save();
            app(ActivityLogger::class)->log('qc_exception_settings.updated', $actor, $setting, ['before' => $before, 'after' => ['enabled' => (bool) $setting->enabled, 'duration_mode' => $setting->duration_mode, 'expires_at' => $setting->expires_at?->toIso8601String()]]);

            return $setting->refresh();
        });
    }
}
