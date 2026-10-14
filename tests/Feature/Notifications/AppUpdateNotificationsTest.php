<?php

namespace Tests\Feature\Notifications;

use App\Enums\EmployeeRole;
use App\Filament\Pages\Notifications;
use App\Jobs\SendMobilePush;
use App\Models\ActivityLog;
use App\Models\MobileDevice;
use App\Models\User;
use App\Notifications\MobileOperationalNotification;
use App\Services\Notifications\AppUpdateNotificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\ResponsibilityTestFoundation;
use Tests\TestCase;

class AppUpdateNotificationsTest extends TestCase
{
    use RefreshDatabase;
    use ResponsibilityTestFoundation;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.default' => 'array', 'mobile.push_enabled' => false]);
    }

    public function test_owner_can_send_an_app_update_alert(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $this->device($recipient, 'android');

        Livewire::actingAs($owner)->test(Notifications::class)
            ->assertActionVisible('sendAppUpdateAlert')
            ->callAction('sendAppUpdateAlert', $this->formData())
            ->assertHasNoActionErrors();

        $notification = $recipient->notifications()->where('type', 'app.update')->sole();

        $this->assertSame('Tech Point Zone Update Available', $notification->data['title']);
        $this->assertSame('A new update is ready.', $notification->data['message']);
        $this->assertSame('general_mobile_update', $notification->data['release_type']);
        $this->assertSame('all', $notification->data['platform']);
        $this->assertArrayNotHasKey('target_id', $notification->data);
    }

    public function test_admin_can_send_an_app_update_alert(): void
    {
        $admin = $this->mobileUser(EmployeeRole::Admin);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $this->device($recipient, 'ios');

        Livewire::actingAs($admin)->test(Notifications::class)
            ->assertActionVisible('sendAppUpdateAlert')
            ->callAction('sendAppUpdateAlert', $this->formData(['platform' => 'ios']))
            ->assertHasNoActionErrors();

        $this->assertSame(1, $recipient->notifications()->where('type', 'app.update')->count());
    }

    public function test_staff_cannot_see_or_execute_the_send_action(): void
    {
        $staff = $this->mobileUser(EmployeeRole::Staff);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $this->device($recipient, 'android');

        Livewire::actingAs($staff)->test(Notifications::class)
            ->assertActionHidden('sendAppUpdateAlert');

        try {
            app(AppUpdateNotificationService::class)->send($staff, $this->formData());
            $this->fail('Staff must not be able to send app update alerts.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $recipient->notifications()->where('type', 'app.update')->count());
        }
    }

    public function test_inactive_admin_cannot_send_an_app_update_alert(): void
    {
        $admin = $this->mobileUser(EmployeeRole::Admin);
        $admin->employee->forceFill(['status' => false])->save();

        $this->assertFalse(app(AppUpdateNotificationService::class)->canSend($admin));
    }

    public function test_manager_cannot_see_or_execute_the_send_action(): void
    {
        $manager = $this->mobileUser(EmployeeRole::Manager);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $this->device($recipient, 'android');

        Livewire::actingAs($manager)->test(Notifications::class)
            ->assertActionHidden('sendAppUpdateAlert');

        try {
            app(AppUpdateNotificationService::class)->send($manager, $this->formData());
            $this->fail('Manager is not an authorized app-update sender.');
        } catch (AuthorizationException) {
            $this->assertSame(0, $recipient->notifications()->where('type', 'app.update')->count());
        }
    }

    public function test_only_active_email_eligible_employees_with_enabled_devices_are_selected(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $eligible = $this->mobileUser(EmployeeRole::Staff);
        $noDevice = $this->mobileUser(EmployeeRole::Staff);
        $disabledDevice = $this->mobileUser(EmployeeRole::Staff);
        $inactive = $this->mobileUser(EmployeeRole::Staff);
        $wrongDomain = $this->mobileUser(EmployeeRole::Staff);
        $this->device($eligible, 'android');
        $this->device($disabledDevice, 'android', disabled: true);
        $this->device($inactive, 'android');
        $this->device($wrongDomain, 'android');
        $inactive->employee->forceFill(['status' => false])->save();
        $wrongDomain->forceFill(['email' => 'not-approved-'.$wrongDomain->id.'@example.org'])->save();
        $wrongDomain->employee->forceFill(['email' => $wrongDomain->email])->save();

        $this->send($owner);

        $this->assertSame(1, $eligible->notifications()->where('type', 'app.update')->count());
        $this->assertSame(0, $noDevice->notifications()->where('type', 'app.update')->count());
        $this->assertSame(0, $disabledDevice->notifications()->where('type', 'app.update')->count());
        $this->assertSame(0, $inactive->notifications()->where('type', 'app.update')->count());
        $this->assertSame(0, $wrongDomain->notifications()->where('type', 'app.update')->count());
    }

    public function test_android_selection_excludes_users_with_only_ios_devices(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $androidUser = $this->mobileUser(EmployeeRole::Staff);
        $iosUser = $this->mobileUser(EmployeeRole::Staff);
        $this->device($androidUser, 'android');
        $this->device($iosUser, 'ios');

        $this->send($owner, ['platform' => 'android']);

        $this->assertSame(1, $androidUser->notifications()->where('type', 'app.update')->count());
        $this->assertSame(0, $iosUser->notifications()->where('type', 'app.update')->count());
    }

    public function test_ios_selection_excludes_users_with_only_android_devices(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $androidUser = $this->mobileUser(EmployeeRole::Staff);
        $iosUser = $this->mobileUser(EmployeeRole::Staff);
        $this->device($androidUser, 'android');
        $this->device($iosUser, 'ios');

        $this->send($owner, ['platform' => 'ios']);

        $this->assertSame(0, $androidUser->notifications()->where('type', 'app.update')->count());
        $this->assertSame(1, $iosUser->notifications()->where('type', 'app.update')->count());
    }

    public function test_all_includes_both_platforms_and_only_one_notification_per_user(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $androidToken = $this->device($recipient, 'android');
        $iosToken = $this->device($recipient, 'ios');

        $this->send($owner, ['platform' => 'all']);
        $notification = $recipient->notifications()->where('type', 'app.update')->sole();

        config(['mobile.push_enabled' => true]);
        Queue::fake();
        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => ['status' => 'ok', 'id' => 'ticket-all'],
        ], 200)]);

        (new SendMobilePush($recipient->id, (string) $notification->id))->handle();

        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => ($request->data()['to'] ?? null) === $androidToken);
        Http::assertSent(fn ($request): bool => ($request->data()['to'] ?? null) === $iosToken);
    }

    public function test_app_update_push_is_targetless_and_platform_filtered_to_each_matching_device(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $androidToken = $this->device($user, 'android');
        $this->device($user, 'ios');
        $user->notify(new MobileOperationalNotification('app.update', [
            'title' => 'Update',
            'message' => 'Install the latest mobile update.',
            'release_type' => 'app_build',
            'platform' => 'android',
            'release_label' => null,
            'mobile_push_enabled' => true,
        ]));
        $notification = $user->notifications()->where('type', 'app.update')->latest()->firstOrFail();

        config(['mobile.push_enabled' => true]);
        Queue::fake();
        Http::fake(['https://exp.host/--/api/v2/push/send' => Http::response([
            'data' => ['status' => 'ok', 'id' => 'ticket-android'],
        ], 200)]);

        (new SendMobilePush($user->id, (string) $notification->id))->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => ($request->data()['to'] ?? null) === $androidToken);
    }

    public function test_ordinary_targetless_notification_still_does_not_send_a_push(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $this->device($user, 'android');
        $user->notify(new MobileOperationalNotification('ordinary.event', [
            'title' => 'Not an app update',
            'message' => 'No record target.',
            'mobile_push_enabled' => true,
        ]));
        $notification = $user->notifications()->where('type', 'ordinary.event')->latest()->firstOrFail();

        config(['mobile.push_enabled' => true]);
        Http::fake();

        (new SendMobilePush($user->id, (string) $notification->id))->handle();

        Http::assertNothingSent();
    }

    public function test_mobile_inbox_exposes_app_update_title_message_type_and_null_target(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('app.update', [
            'title' => 'ERP Mobile Update',
            'message' => 'A compatible update is available.',
            'release_type' => 'ota_update',
            'platform' => 'all',
            'release_label' => 'Minor ERP Update',
            'mobile_push_enabled' => true,
        ]));
        $notification = $user->notifications()->where('type', 'app.update')->latest()->firstOrFail();

        $response = $this->withToken($user->createToken('app update inbox')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/notifications')
            ->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', (string) $notification->id);
        $this->assertSame('ERP Mobile Update', $item['title']);
        $this->assertSame('A compatible update is available.', $item['subtitle']);
        $this->assertSame('app.update', $item['type']);
        $this->assertNull($item['target']);
    }

    public function test_arbitrary_targetless_mobile_notification_keeps_restricted_generic_content(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('ordinary.event', [
            'title' => 'Secret title',
            'message' => 'Secret message',
        ]));
        $notification = $user->notifications()->where('type', 'ordinary.event')->latest()->firstOrFail();

        $response = $this->withToken($user->createToken('restricted inbox')->plainTextToken)
            ->getJson('/api/mobile/v1/workspace/notifications')
            ->assertOk();

        $item = collect($response->json('data'))->firstWhere('id', (string) $notification->id);
        $this->assertSame('ERP notification', $item['title']);
        $this->assertSame('Open the ERP for details, or access is no longer available.', $item['subtitle']);
        $this->assertNull($item['type']);
        $this->assertNull($item['target']);
        $this->assertStringNotContainsString('Secret message', $response->getContent());
    }

    public function test_reading_app_update_marks_it_read_and_returns_null_target(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('app.update', [
            'title' => 'ERP Mobile Update',
            'message' => 'A compatible update is available.',
            'platform' => 'all',
            'mobile_push_enabled' => true,
        ]));
        $notification = $user->notifications()->where('type', 'app.update')->latest()->firstOrFail();

        $response = $this->withToken($user->createToken('read app update')->plainTextToken)
            ->postJson('/api/mobile/v1/workspace/notifications/'.$notification->id.'/read')
            ->assertOk();

        $this->assertNull($response->json('data.target'));
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_web_inbox_renders_app_update_title_and_message(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('app.update', [
            'title' => 'ERP Mobile Update',
            'message' => 'Compatible mobile improvements are ready.',
        ]));

        Livewire::actingAs($user)->test(Notifications::class)
            ->assertSee('ERP Mobile Update')
            ->assertSee('Compatible mobile improvements are ready.');
    }

    public function test_web_open_marks_app_update_read_and_returns_to_notifications_page(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('app.update', ['title' => 'Update', 'message' => 'New build']));
        $notification = $user->notifications()->where('type', 'app.update')->latest()->firstOrFail();

        Livewire::actingAs($user)->test(Notifications::class)
            ->call('open', (string) $notification->id)
            ->assertRedirect(Notifications::getUrl());

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_web_open_arbitrary_targetless_notification_keeps_unavailable_record_behavior(): void
    {
        $user = $this->mobileUser(EmployeeRole::Staff);
        $user->notify(new MobileOperationalNotification('ordinary.event', ['title' => 'Notice', 'message' => 'No target']));
        $notification = $user->notifications()->where('type', 'ordinary.event')->latest()->firstOrFail();

        Livewire::actingAs($user)->test(Notifications::class)
            ->call('open', (string) $notification->id)
            ->assertNotified('This record is no longer available to you.');

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_audit_records_safe_metadata_and_never_contains_expo_tokens(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $expoToken = $this->device($recipient, 'android');

        $this->send($owner, [
            'release_type' => 'app_build',
            'release_label' => 'Android 1.4.0 Build 14',
            'message' => 'Install the latest Android build.',
        ]);

        $activity = ActivityLog::query()->where('event', 'mobile.app_update_alert_sent')->sole();
        $this->assertSame($owner->id, $activity->actor_user_id);
        $this->assertSame('all', $activity->properties['platform_selection']);
        $this->assertSame('app_build', $activity->properties['update_type']);
        $this->assertSame('Android 1.4.0 Build 14', $activity->properties['release_label']);
        $this->assertSame(1, $activity->properties['recipient_user_count']);
        $this->assertSame(1, $activity->properties['target_device_count']);
        $this->assertArrayHasKey('sent_at', $activity->properties);
        $this->assertStringNotContainsString($expoToken, json_encode($activity->properties));
    }

    public function test_identical_livewire_resubmission_is_deduplicated(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);
        $recipient = $this->mobileUser(EmployeeRole::Staff);
        $this->device($recipient, 'android');
        $form = $this->formData(['message' => 'Send this once.']);

        Livewire::actingAs($owner)->test(Notifications::class)
            ->callAction('sendAppUpdateAlert', $form)
            ->assertHasNoActionErrors();

        Livewire::actingAs($owner)->test(Notifications::class)
            ->callAction('sendAppUpdateAlert', $form)
            ->assertHasNoActionErrors();

        $this->assertSame(1, $recipient->notifications()->where('type', 'app.update')->count());
        $this->assertSame(1, ActivityLog::query()->where('event', 'mobile.app_update_alert_sent')->count());
    }

    public function test_form_rejects_invalid_platform_and_messages_over_240_characters(): void
    {
        $owner = $this->mobileUser(EmployeeRole::Owner);

        Livewire::actingAs($owner)->test(Notifications::class)
            ->callAction('sendAppUpdateAlert', $this->formData(['platform' => 'desktop']))
            ->assertHasActionErrors(['platform']);

        Livewire::actingAs($owner)->test(Notifications::class)
            ->callAction('sendAppUpdateAlert', $this->formData(['message' => str_repeat('x', 241)]))
            ->assertHasActionErrors(['message']);
    }

    /** @return array<string, mixed> */
    private function formData(array $overrides = []): array
    {
        return array_replace([
            'platform' => 'all',
            'release_type' => 'general_mobile_update',
            'title' => 'Tech Point Zone Update Available',
            'message' => 'A new update is ready.',
            'release_label' => '',
        ], $overrides);
    }

    private function send(User $actor, array $overrides = []): array
    {
        return app(AppUpdateNotificationService::class)->send($actor, $this->formData($overrides));
    }

    private function mobileUser(EmployeeRole $role): User
    {
        $user = $this->responsibilityUser($role);
        $email = 'app-update-'.$user->id.'@techpointzone.com';
        $user->forceFill(['email' => $email])->save();
        $user->employee->forceFill(['email' => $email])->save();

        return $user->refresh();
    }

    private function device(User $user, string $platform, bool $disabled = false): string
    {
        $user->createToken('App update test device '.$platform.' '.Str::uuid());
        $accessToken = $user->tokens()->latest('id')->firstOrFail();
        $expoToken = 'ExponentPushToken['.Str::uuid().']';

        MobileDevice::query()->create([
            'user_id' => $user->id,
            'personal_access_token_id' => $accessToken->id,
            'device_id' => (string) Str::uuid(),
            'token_hash' => hash('sha256', $expoToken),
            'expo_token' => $expoToken,
            'platform' => $platform,
            'last_seen_at' => now(),
            'disabled_at' => $disabled ? now() : null,
        ]);

        return $expoToken;
    }
}
