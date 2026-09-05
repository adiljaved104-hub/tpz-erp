<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Chat;
use App\Models\ConversationMessage;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Chat\ChatQueryService;
use App\Services\Chat\ChatRecordMentionService;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationPresenter;
use App\Services\Chat\ConversationService;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class InternalChatModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_chat_page_opens_existing_conversation_and_sends_reads_and_replies(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [$secondUser, $second] = $this->employee(EmployeeRole::Staff);
        $service = app(ConversationService::class);
        $conversation = $service->direct($firstUser, $second);

        $this->actingAs($firstUser);
        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->assertOk()
            ->set('body', 'First message')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $first = ConversationMessage::query()->sole();
        $this->assertSame($conversation->id, $service->direct($firstUser, $second)->id);
        $this->assertSame(0, app(ConversationMessageService::class)->unreadCount($firstUser, $conversation));
        $this->assertSame(1, app(ConversationMessageService::class)->unreadCount($secondUser, $conversation));
        $this->assertSame(1, app(ChatQueryService::class)->totalUnread($secondUser));

        $this->actingAs($secondUser);
        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->assertOk()
            ->call('replyTo', $first->id)
            ->set('body', 'Reply message')
            ->call('sendMessage')
            ->assertHasNoErrors();
        $this->assertDatabaseHas('conversation_messages', ['body' => 'Reply message', 'reply_to_message_id' => $first->id]);
        $this->assertSame(0, app(ConversationMessageService::class)->unreadCount($secondUser, $conversation));
    }

    public function test_self_chat_is_rejected(): void
    {
        [$user, $employee] = $this->employee(EmployeeRole::Staff);

        $this->expectException(ValidationException::class);
        app(ConversationService::class)->direct($user, $employee);
    }

    public function test_team_access_and_unread_follow_current_membership_per_employee(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        [$firstUser] = $this->employee(EmployeeRole::Staff, $team);
        [$secondUser, $second] = $this->employee(EmployeeRole::Staff, $team);
        [$outsiderUser] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->team($firstUser, $team);
        $message = app(ConversationMessageService::class)->send($firstUser, $conversation, 'Team update');

        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($secondUser, $conversation));
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($outsiderUser, $conversation));
        $this->assertSame(1, app(ConversationMessageService::class)->unreadCount($secondUser, $conversation));
        app(ConversationMessageService::class)->markRead($secondUser, $conversation, $message);
        $this->assertSame(0, app(ConversationMessageService::class)->unreadCount($secondUser, $conversation));

        $second->update(['team_id' => null]);
        $secondUser->unsetRelation('employee');
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($secondUser, $conversation));
    }

    public function test_owner_and_admin_can_browse_team_chat_without_membership_but_staff_cannot(): void
    {
        $team = Team::query()->create(['name' => 'Website Operations', 'status' => true]);
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin] = $this->employee(EmployeeRole::Admin);
        [$staff] = $this->employee(EmployeeRole::Staff);

        $conversation = app(ConversationService::class)->team($owner, $team);

        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($owner, $conversation));
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($admin, $conversation));
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($staff, $conversation));
        $this->assertSame([$team->id => $team->name], app(ChatQueryService::class)->teamOptions($owner));
        $this->assertSame([$team->id => $team->name], app(ChatQueryService::class)->teamOptions($admin));
        $this->assertSame([], app(ChatQueryService::class)->teamOptions($staff));

        $this->actingAs($owner);
        Livewire::test(Chat::class)->assertActionVisible('teamChat');

        $this->expectException(AuthorizationException::class);
        app(ConversationService::class)->team($staff, $team);
    }

    public function test_chat_team_override_denial_blocks_admin_ui_and_server_access(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin, $adminEmployee] = $this->employee(EmployeeRole::Admin);
        $team = Team::query()->create(['name' => 'Marketing', 'status' => true]);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $adminEmployee->id,
            'permission_key' => ChatPermission::Team->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Test denial',
        ]);

        $this->actingAs($admin);
        Livewire::test(Chat::class)->assertActionHidden('teamChat');
        $this->expectException(AuthorizationException::class);
        app(ConversationService::class)->team($admin, $team);
    }

    public function test_context_conversation_is_unique_and_source_revocation_removes_access_and_link(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $staffEmployee] = $this->employee(EmployeeRole::Staff);
        [, $otherEmployee] = $this->employee(EmployeeRole::Staff);
        $task = $this->task($owner, $staffEmployee);
        $service = app(ConversationService::class);
        $conversation = $service->context($staff, $task, 'Task '.$task->reference);

        $this->assertSame($conversation->id, $service->context($staff, $task)->id);
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($staff, $conversation));
        $this->assertNotNull(app(ConversationPresenter::class)->contextLink($conversation->load('context'), $staff));
        $this->actingAs($staff)->get(Chat::getUrl(['contextType' => 'task', 'contextId' => $task->id]))->assertOk();

        $task->update(['assigned_employee_id' => $otherEmployee->id]);
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($staff, $conversation->fresh()));
        $this->assertNull(app(ConversationPresenter::class)->contextLink($conversation->fresh()->load('context'), $staff));
        $this->assertSame(0, app(ChatQueryService::class)->inbox($staff)->count());
        $this->actingAs($staff)->get(Chat::getUrl(['conversation' => $conversation->id]))->assertForbidden();
    }

    public function test_mentions_notify_only_eligible_recipient_and_notification_reauthorizes(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [$secondUser, $second] = $this->employee(EmployeeRole::Staff);
        [, $outsider] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($firstUser, $second);

        $this->actingAs($firstUser);
        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->callAction('mentionEmployees', ['employee_ids' => [$second->id]])
            ->assertHasNoActionErrors()
            ->assertSet('mentionEmployeeIds', [$second->id]);

        app(ConversationMessageService::class)->send($firstUser, $conversation, 'Please review', null, [$second->id]);

        $notification = $secondUser->notifications()->sole();
        $this->assertSame('chat.mention', $notification->type);
        $this->assertSame(0, $firstUser->notifications()->count());
        $this->assertTrue(app(NotificationInboxService::class)->open($secondUser, $notification->id)['available']);

        try {
            app(ConversationMessageService::class)->send($firstUser, $conversation, 'Invalid mention', null, [$outsider->id]);
            $this->fail('An unrelated Employee was mentioned.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('conversation_messages', ['body' => 'Invalid mention']);
        }

        $conversation->participants()->where('employee_id', $second->id)->update(['left_at' => now()]);
        $this->assertFalse(app(NotificationInboxService::class)->open($secondUser, $notification->id)['available']);
    }

    public function test_authorized_business_record_mention_renders_link_and_revocation_restricts_it(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $staffEmployee] = $this->employee(EmployeeRole::Staff);
        [, $otherEmployee] = $this->employee(EmployeeRole::Staff);
        $task = $this->task($owner, $staffEmployee);
        $conversation = app(ConversationService::class)->direct($staff, $owner->employee);
        $mentions = app(ChatRecordMentionService::class);

        $this->assertArrayHasKey($task->id, $mentions->search($staff, 'task', $task->reference));
        $this->actingAs($staff);
        Livewire::withQueryParams(['conversation' => $conversation->id])->test(Chat::class)
            ->callAction('mentionRecord', ['record_type' => 'task', 'record_id' => $task->id])
            ->assertHasNoActionErrors()
            ->assertSet('body', "[[erp-record:task:{$task->id}]]");
        $body = $mentions->appendToken($staff, 'Please review', 'task', $task->id);
        $message = app(ConversationMessageService::class)->send($staff, $conversation, $body);

        $this->assertSame('direct', $conversation->fresh()->type->value);
        $segment = collect($mentions->segmentsForMessages(collect([$message]), $staff)[$message->id])->firstWhere('type', 'record');
        $this->assertSame('Task — '.$task->reference, $segment['label']);
        $this->assertNotNull($segment['url']);
        $this->assertSame('chat.direct_message', $owner->notifications()->sole()->type);

        $task->update(['assigned_employee_id' => $otherEmployee->id, 'created_by_user_id' => $owner->id]);
        $this->assertArrayNotHasKey($task->id, $mentions->search($staff, 'task', $task->reference));
        $restricted = collect($mentions->segmentsForMessages(collect([$message]), $staff)[$message->id])->firstWhere('type', 'record');
        $this->assertSame('Restricted Record', $restricted['label']);
        $this->assertNull($restricted['url']);
    }

    public function test_chat_filters_and_reply_presentation_are_livewire_scoped(): void
    {
        [$user] = $this->employee(EmployeeRole::Staff);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        $team = Team::query()->create(['name' => 'Sales', 'status' => true]);
        $user->employee->update(['team_id' => $team->id]);
        $user->unsetRelation('employee');
        $direct = app(ConversationService::class)->direct($user, $peer);
        app(ConversationService::class)->team($user, $team);
        $message = app(ConversationMessageService::class)->send($user, $direct, 'Presentation check');

        $this->actingAs($user);
        Livewire::withQueryParams(['conversation' => $direct->id])->test(Chat::class)
            ->assertSeeHtml('data-message-alignment="outgoing"')
            ->call('replyTo', $message->id)
            ->assertSet('replyToMessageId', $message->id)
            ->assertSee('Replying to')
            ->call('clearReply')
            ->assertSet('replyToMessageId', null)
            ->call('setFilter', 'teams')
            ->assertSet('filter', 'teams')
            ->assertSee('Sales')
            ->assertDontSee('Presentation check');
    }

    public function test_reply_cannot_cross_conversation_and_message_limits_remain_enforced(): void
    {
        [$user] = $this->employee(EmployeeRole::Staff);
        [, $firstPeer] = $this->employee(EmployeeRole::Staff);
        [, $secondPeer] = $this->employee(EmployeeRole::Staff);
        $one = app(ConversationService::class)->direct($user, $firstPeer);
        $two = app(ConversationService::class)->direct($user, $secondPeer);
        $foreignMessage = app(ConversationMessageService::class)->send($user, $two, 'Other conversation');

        foreach (['', str_repeat('x', 5001)] as $invalid) {
            try {
                app(ConversationMessageService::class)->send($user, $one, $invalid);
                $this->fail('Invalid message was accepted.');
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->expectException(ValidationException::class);
        app(ConversationMessageService::class)->send($user, $one, 'Cross reply', $foreignMessage);
    }

    public function test_chat_permissions_hide_navigation_actions_and_deny_direct_url(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $employee] = $this->employee(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $employee->id,
            'permission_key' => ChatPermission::View->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Test denial',
        ]);

        $this->actingAs($staff);
        $this->assertFalse(Chat::shouldRegisterNavigation());
        $this->get(Chat::getUrl())->assertForbidden();
    }

    public function test_specific_permission_denials_hide_or_block_each_chat_mode(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $employee] = $this->employee(EmployeeRole::Staff);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        foreach ([ChatPermission::Direct, ChatPermission::Team, ChatPermission::Context] as $permission) {
            EmployeePermissionOverride::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'permission_key' => $permission->value],
                ['effect' => EmployeePermissionEffect::Deny, 'granted_by_user_id' => $owner->id, 'reason' => 'Test denial'],
            );
        }

        $this->actingAs($staff);
        Livewire::test(Chat::class)->assertActionHidden('startDirect')->assertActionHidden('teamChat');
        $this->assertFalse(app(ChatAuthorization::class)->allows($staff, ChatPermission::Direct));
        $this->assertFalse(app(ChatAuthorization::class)->allows($staff, ChatPermission::Team));
        $this->assertFalse(app(ChatAuthorization::class)->allows($staff, ChatPermission::Context));
        $this->expectException(AuthorizationException::class);
        app(ConversationService::class)->direct($staff, $peer);
    }

    public function test_archive_is_management_only_and_preserves_history(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($owner, $peer);
        $message = app(ConversationMessageService::class)->send($owner, $conversation, 'Preserve me');

        app(ConversationService::class)->archive($owner, $conversation);

        $this->assertDatabaseHas('conversations', ['id' => $conversation->id, 'status' => 'archived']);
        $this->assertDatabaseHas('conversation_messages', ['id' => $message->id, 'body' => 'Preserve me']);
        $this->assertSame(0, app(ChatQueryService::class)->inbox($owner)->count());
        $this->assertSame(1, app(ChatQueryService::class)->inbox($owner, '', true)->count());
    }

    public function test_ordinary_participant_cannot_globally_archive_shared_history(): void
    {
        [$staff] = $this->employee(EmployeeRole::Staff);
        [, $peer] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($staff, $peer);

        $this->expectException(AuthorizationException::class);
        app(ConversationService::class)->archive($staff, $conversation);
    }

    /** @return array{User, Employee} */
    private function employee(EmployeeRole $role, ?Team $team = null): array
    {
        $user = User::factory()->create(['email' => fake()->unique()->userName().'@techpointzone.com']);
        $employee = Employee::factory()->for($user)->role($role)->create([
            'email' => $user->email,
            'team_id' => $team?->id,
        ]);

        return [$user->refresh(), $employee];
    }

    private function task(User $creator, Employee $assignee): Task
    {
        return Task::query()->create([
            'reference' => 'TSK-CHAT-'.Str::upper(Str::random(5)),
            'title' => 'Chat source task',
            'status' => 'assigned',
            'priority' => 'normal',
            'assigned_employee_id' => $assignee->id,
            'created_by_user_id' => $creator->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
