<?php

namespace Tests\Feature\Mobile;

use App\Enums\EmployeeRole;
use App\Models\WarningCategory;
use App\Services\Hr\EmployeeWarningService;
use App\Services\Hr\HrNoticeService;
use App\Services\Mobile\NotificationTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class MobileHrNotificationTargetTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    public function test_hr_notice_and_warning_targets_respect_existing_hr_authorization(): void
    {
        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);
        $outsider = $this->responsibilityUser(EmployeeRole::Staff);

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'team_id' => null,
            'employee_ids' => [$staff->employee->id],
            'title' => 'Private HR notice',
            'content' => 'Only the intended recipient should resolve this notice.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);

        $category = WarningCategory::query()->create([
            'name' => 'Conduct',
            'status' => true,
            'created_by_user_id' => $owner->id,
        ]);

        $warning = app(EmployeeWarningService::class)->issue([
            'employee_id' => $staff->employee->id,
            'warning_level' => 'verbal',
            'warning_category_id' => $category->id,
            'title' => 'Private warning',
            'description' => 'Only the warned employee should resolve this warning.',
            'issued_date' => now()->toDateString(),
            'acknowledgment_required' => false,
        ], $owner);

        $target = app(NotificationTarget::class);

        $this->assertSame(
            ['module' => 'hr', 'id' => $notice->id],
            $target->resolve($staff, ['target_type' => 'hr_notice', 'target_id' => $notice->id]),
        );
        $this->assertNull(
            $target->resolve($outsider, ['target_type' => 'hr_notice', 'target_id' => $notice->id]),
        );
        $this->assertSame(
            ['module' => 'hr', 'id' => $warning->id],
            $target->resolve($staff, ['target_type' => 'employee_warning', 'target_id' => $warning->id]),
        );
        $this->assertNull(
            $target->resolve($outsider, ['target_type' => 'employee_warning', 'target_id' => $warning->id]),
        );
    }

    public function test_hr_notice_push_uses_authorized_notice_title_and_message(): void
    {
        config(['mobile.push_enabled' => false]);

        $owner = $this->responsibilityUser(EmployeeRole::Owner);
        $staff = $this->responsibilityUser(EmployeeRole::Staff);

        $email = 'mobile-push-'.$staff->id.'@techpointzone.com';
        $staff->forceFill(['email' => $email])->save();
        $staff->employee->forceFill(['email' => $email])->save();
        $staff = $staff->refresh();

        $notice = app(HrNoticeService::class)->publish([
            'audience_type' => 'selected',
            'team_id' => null,
            'employee_ids' => [$staff->employee->id],
            'title' => 'Push delivery notice',
            'content' => 'Push delivery test content.',
            'priority' => 'normal',
            'published_at' => now()->toDateTimeString(),
            'expires_at' => null,
            'acknowledgment_required' => false,
        ], $owner);

        $notification = $staff->notifications()
            ->where('type', 'notice.published')
            ->firstOrFail();

        $staff->createToken('Mobile push test');
        $accessToken = $staff->tokens()->latest('id')->firstOrFail();

        $expoToken = 'ExponentPushToken[hrnotice123]';

        \App\Models\MobileDevice::query()->create([
            'user_id' => $staff->id,
            'personal_access_token_id' => $accessToken->id,
            'device_id' => (string) \Illuminate\Support\Str::uuid(),
            'token_hash' => hash('sha256', $expoToken),
            'expo_token' => $expoToken,
            'platform' => 'android',
            'last_seen_at' => now(),
            'disabled_at' => null,
        ]);

        config(['mobile.push_enabled' => true]);

        \Illuminate\Support\Facades\Queue::fake();

        \Illuminate\Support\Facades\Http::fake([
            'https://exp.host/--/api/v2/push/send' => \Illuminate\Support\Facades\Http::response([
                'data' => [
                    'status' => 'ok',
                    'id' => 'expo-ticket-1',
                ],
            ], 200),
        ]);

        (new \App\Jobs\SendMobilePush(
            $staff->id,
            (string) $notification->id,
        ))->handle();

        \Illuminate\Support\Facades\Http::assertSent(function ($request) use ($expoToken, $notice, $notification): bool {
            $payload = $request->data();

            return $request->url() === 'https://exp.host/--/api/v2/push/send'
                && ($payload['to'] ?? null) === $expoToken
                && ($payload['title'] ?? null) === 'HR Notice'
                && ($payload['body'] ?? null) === $notice->reference.' — Push delivery notice'
                && ($payload['sound'] ?? null) === 'default'
                && ($payload['channelId'] ?? null) === 'erp-alerts'
                && ($payload['data']['notification_id'] ?? null) === (string) $notification->id;
        });
    }
}
