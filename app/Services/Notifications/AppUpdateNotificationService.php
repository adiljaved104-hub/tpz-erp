<?php

namespace App\Services\Notifications;

use App\Enums\EmployeeRole;
use App\Models\User;
use App\Notifications\MobileOperationalNotification;
use App\Services\ActivityLogger;
use App\Services\CompanyEmailPolicyService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AppUpdateNotificationService
{
    private const IDEMPOTENCY_WINDOW_SECONDS = 45;

    public function __construct(
        private readonly CompanyEmailPolicyService $emailPolicy,
        private readonly ActivityLogger $activity,
    ) {}

    public function canSend(User $user): bool
    {
        return $user->employee()
            ->where('status', true)
            ->whereIn('role', [EmployeeRole::Owner->value, EmployeeRole::Admin->value])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{sent: bool, duplicate: bool, recipient_user_count: int, target_device_count: int}
     */
    public function send(User $actor, array $input): array
    {
        if (! $this->canSend($actor)) {
            throw new AuthorizationException('Only active Owner and Admin employees can send app update alerts.');
        }

        $data = Validator::make($input, [
            'platform' => ['required', 'string', Rule::in(['all', 'android', 'ios'])],
            'release_type' => ['required', 'string', Rule::in(['app_build', 'ota_update', 'general_mobile_update'])],
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'string', 'max:240'],
            'release_label' => ['nullable', 'string', 'max:80'],
        ])->validate();

        $data['title'] = trim((string) ($data['title'] ?? '')) ?: 'Tech Point Zone Update Available';
        $data['message'] = trim((string) $data['message']);
        $data['release_label'] = trim((string) ($data['release_label'] ?? '')) ?: null;

        if ($data['message'] === '') {
            throw ValidationException::withMessages(['message' => 'Enter a message for the app update alert.']);
        }

        $fingerprint = hash('sha256', json_encode([
            'actor' => $actor->getKey(),
            'platform' => $data['platform'],
            'release_type' => $data['release_type'],
            'title' => $data['title'],
            'message' => $data['message'],
            'release_label' => $data['release_label'],
        ], JSON_THROW_ON_ERROR));
        $idempotencyKey = 'mobile.app-update-alert.'.$fingerprint;

        if (! Cache::add($idempotencyKey, 'sending', now()->addSeconds(self::IDEMPOTENCY_WINDOW_SECONDS))) {
            return ['sent' => false, 'duplicate' => true, 'recipient_user_count' => 0, 'target_device_count' => 0];
        }

        $recipientCount = 0;
        $deviceCount = 0;

        DB::transaction(function () use ($actor, $data, &$recipientCount, &$deviceCount): void {
            $platforms = $data['platform'] === 'all' ? ['android', 'ios'] : [$data['platform']];
            $matchingDevices = fn (Builder $query): Builder => $query
                ->whereNull('disabled_at')
                ->whereIn('platform', $platforms);

            User::query()
                ->whereHas('employee', fn (Builder $query): Builder => $query->where('status', true))
                ->whereHas('mobileDevices', $matchingDevices)
                ->with('employee')
                ->withCount(['mobileDevices as matching_mobile_device_count' => $matchingDevices])
                ->orderBy('id')
                ->chunkById(100, function ($users) use ($data, &$recipientCount, &$deviceCount): void {
                    foreach ($users as $user) {
                        if (! $this->emailPolicy->allowsAuthentication($user)) {
                            continue;
                        }

                        $user->notify(new MobileOperationalNotification('app.update', [
                            'title' => $data['title'],
                            'message' => $data['message'],
                            'release_type' => $data['release_type'],
                            'platform' => $data['platform'],
                            'release_label' => $data['release_label'],
                            'mobile_push_enabled' => true,
                        ]));

                        $recipientCount++;
                        $deviceCount += (int) $user->matching_mobile_device_count;
                    }
                });

            if ($recipientCount > 0) {
                $this->activity->log('mobile.app_update_alert_sent', $actor, null, [
                    'platform_selection' => $data['platform'],
                    'update_type' => $data['release_type'],
                    'release_label' => $data['release_label'],
                    'recipient_user_count' => $recipientCount,
                    'target_device_count' => $deviceCount,
                    'sent_at' => now()->toIso8601String(),
                ]);
            }
        });

        if ($recipientCount === 0) {
            Cache::forget($idempotencyKey);

            return ['sent' => false, 'duplicate' => false, 'recipient_user_count' => 0, 'target_device_count' => 0];
        }

        Cache::put($idempotencyKey, 'sent', now()->addSeconds(self::IDEMPOTENCY_WINDOW_SECONDS));

        return ['sent' => true, 'duplicate' => false, 'recipient_user_count' => $recipientCount, 'target_device_count' => $deviceCount];
    }
}
