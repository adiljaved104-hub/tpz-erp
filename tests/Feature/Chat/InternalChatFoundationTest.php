<?php

namespace Tests\Feature\Chat;

use App\Enums\ChatPermission;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InternalChatFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_conversation_is_created_once_for_the_same_pair(): void
    {
        [$firstUser, $first] = $this->employee(EmployeeRole::Staff);
        [, $second] = $this->employee(EmployeeRole::Staff);

        $one = app(ConversationService::class)->direct($firstUser, $second);
        $two = app(ConversationService::class)->direct($firstUser, $second);

        $this->assertTrue($one->is($two));
        $this->assertCount(2, $one->participants);
        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseHas('conversation_participants', ['conversation_id' => $one->id, 'employee_id' => $first->id]);
        $this->assertDatabaseHas('activity_logs', ['event' => 'chat.conversation_created', 'subject_type' => 'conversation', 'subject_id' => $one->id]);
    }

    public function test_team_conversation_uses_current_team_membership_for_access(): void
    {
        $team = Team::query()->create(['name' => 'Operations', 'status' => true]);
        [$memberUser] = $this->employee(EmployeeRole::Staff, $team);
        [$outsiderUser] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->team($memberUser, $team);

        $this->assertTrue($conversation->team->is($team));
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($memberUser, $conversation));
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($outsiderUser, $conversation));

        $memberUser->employee->update(['team_id' => null]);
        $memberUser->unsetRelation('employee');
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($memberUser, $conversation));
    }

    public function test_context_conversation_uses_safe_morph_alias_and_source_authorization(): void
    {
        [$ownerUser] = $this->employee(EmployeeRole::Owner);
        [$staffUser, $staff] = $this->employee(EmployeeRole::Staff);
        $task = $this->task($ownerUser);

        $conversation = app(ConversationService::class)->context($ownerUser, $task, 'Task discussion');

        $this->assertSame('task', $conversation->context_type);
        $this->assertTrue($conversation->context->is($task));
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($ownerUser, $conversation));
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($staffUser, $conversation));

        $task->update(['assigned_employee_id' => $staff->id]);
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($staffUser, $conversation->fresh()));
    }

    public function test_participant_cannot_access_an_unrelated_direct_conversation(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [, $second] = $this->employee(EmployeeRole::Staff);
        [$outsiderUser] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($firstUser, $second);

        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($outsiderUser, $conversation));
        $this->expectException(AuthorizationException::class);
        app(ConversationMessageService::class)->send($outsiderUser, $conversation, 'Not permitted');
    }

    public function test_message_service_rejects_blank_or_markup_content(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [, $second] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($firstUser, $second);

        try {
            app(ConversationMessageService::class)->send($firstUser, $conversation, '   ');
            $this->fail('Blank message was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('body', $exception->errors());
        }

        $this->expectException(ValidationException::class);
        app(ConversationMessageService::class)->send($firstUser, $conversation, '<script>alert(1)</script>');
    }

    public function test_read_state_is_independent_for_each_participant(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [$secondUser, $second] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($firstUser, $second);
        $messages = app(ConversationMessageService::class);
        $message = $messages->send($firstUser, $conversation, 'Please review this.');

        $this->assertSame(1, $messages->unreadCount($secondUser, $conversation));
        $messages->markRead($secondUser, $conversation, $message);
        $this->assertSame(0, $messages->unreadCount($secondUser, $conversation));
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'employee_id' => $second->id,
            'last_read_message_id' => $message->id,
        ]);
        $this->assertDatabaseHas('conversation_participants', [
            'conversation_id' => $conversation->id,
            'employee_id' => $firstUser->employee->id,
            'last_read_message_id' => null,
        ]);
    }

    public function test_employee_permission_override_is_respected(): void
    {
        [$ownerUser] = $this->employee(EmployeeRole::Owner);
        [$staffUser, $staff] = $this->employee(EmployeeRole::Staff);
        [, $other] = $this->employee(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $staff->id,
            'permission_key' => ChatPermission::Direct->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $ownerUser->id,
            'reason' => 'Focused authorization test',
        ]);

        $this->assertFalse(app(ChatAuthorization::class)->allows($staffUser, ChatPermission::Direct));
        $this->expectException(AuthorizationException::class);
        app(ConversationService::class)->direct($staffUser, $other);
    }

    public function test_role_defaults_allow_work_chat_but_reserve_management_for_owner_and_admin(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$admin] = $this->employee(EmployeeRole::Admin);
        [$manager] = $this->employee(EmployeeRole::Manager);
        [$staff] = $this->employee(EmployeeRole::Staff);
        $authorization = app(ChatAuthorization::class);

        foreach ([$owner, $admin, $manager, $staff] as $user) {
            $this->assertTrue($authorization->allows($user, ChatPermission::View));
            $this->assertTrue($authorization->allows($user, ChatPermission::Direct));
            $this->assertTrue($authorization->allows($user, ChatPermission::Team));
            $this->assertTrue($authorization->allows($user, ChatPermission::Context));
        }
        $this->assertTrue($authorization->allows($owner, ChatPermission::Manage));
        $this->assertTrue($authorization->allows($admin, ChatPermission::Manage));
        $this->assertFalse($authorization->allows($manager, ChatPermission::Manage));
        $this->assertFalse($authorization->allows($staff, ChatPermission::Manage));
    }

    public function test_restrictive_foreign_keys_and_model_guard_preserve_history(): void
    {
        [$firstUser] = $this->employee(EmployeeRole::Staff);
        [, $second] = $this->employee(EmployeeRole::Staff);
        $conversation = app(ConversationService::class)->direct($firstUser, $second);
        app(ConversationMessageService::class)->send($firstUser, $conversation, 'Permanent history');

        try {
            DB::table('conversations')->where('id', $conversation->id)->delete();
            $this->fail('Restrictive foreign key allowed conversation deletion.');
        } catch (QueryException) {
            $this->assertDatabaseHas('conversations', ['id' => $conversation->id]);
        }

        $this->expectException(\LogicException::class);
        $conversation->delete();
    }

    public function test_morph_map_remains_enforced_and_chat_alias_is_stable(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());
        $this->assertSame(Conversation::class, Relation::getMorphedModel('conversation'));
        $this->assertSame('conversation', (new Conversation)->getMorphClass());
    }

    /** @return array{User, Employee} */
    private function employee(EmployeeRole $role, ?Team $team = null): array
    {
        $employee = Employee::factory()->role($role)->create(['team_id' => $team?->id]);

        return [$employee->user, $employee];
    }

    private function task(User $creator): Task
    {
        return Task::query()->create([
            'reference' => 'TSK-TEST-'.Str::upper(Str::random(6)),
            'title' => 'Chat authorization task',
            'status' => 'assigned',
            'priority' => 'normal',
            'created_by_user_id' => $creator->id,
            'idempotency_key' => (string) Str::uuid(),
        ]);
    }
}
