<?php

namespace Tests\Feature\Chat;

use App\Enums\ChannelVisibility;
use App\Enums\ChatPermission;
use App\Enums\ConversationType;
use App\Enums\EmployeePermissionEffect;
use App\Enums\EmployeeRole;
use App\Filament\Pages\Chat;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\EmployeePermissionOverride;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Chat\ChannelService;
use App\Services\Chat\ChatInteractionService;
use App\Services\Chat\ChatQueryService;
use App\Services\Chat\ConversationMessageService;
use App\Services\Chat\ConversationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class InternalChatSlackUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_schema_is_additive_and_existing_conversation_modes_still_work(): void
    {
        $this->assertTrue(Schema::hasColumns('conversations', ['slug', 'description', 'visibility']));
        $this->assertTrue(Schema::hasTable('conversation_message_reactions'));
        $this->assertTrue(Schema::hasTable('conversation_message_pins'));

        [$owner] = $this->employee(EmployeeRole::Owner);
        [, $staff] = $this->employee(EmployeeRole::Staff);
        $direct = app(ConversationService::class)->direct($owner, $staff);
        $this->assertSame(ConversationType::Direct, $direct->type);
        $this->assertNull($direct->slug);
    }

    public function test_public_channel_can_be_created_discovered_joined_and_left_without_losing_history(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff] = $this->employee(EmployeeRole::Staff);
        $channel = app(ChannelService::class)->create($owner, 'General Operations', ChannelVisibility::Public, 'Daily coordination');

        $this->assertSame('general-operations', $channel->slug);
        $this->assertTrue(app(ChatQueryService::class)->publicChannels($staff)->whereKey($channel)->exists());
        app(ChannelService::class)->join($staff, $channel);
        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($staff, $channel));
        $message = app(ConversationMessageService::class)->send($staff, $channel, 'Joined safely');

        app(ChannelService::class)->leave($staff, $channel);
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($staff, $channel));
        $this->assertDatabaseHas('conversation_messages', ['id' => $message->id, 'body' => 'Joined safely']);
    }

    public function test_private_channel_is_membership_only_and_management_is_permission_controlled(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$memberUser, $member] = $this->employee(EmployeeRole::Staff);
        [$outsider] = $this->employee(EmployeeRole::Staff);
        $channel = app(ChannelService::class)->create($owner, 'Finance Ops', 'private', null, [$member->id]);

        $this->assertTrue(app(ChatAuthorization::class)->canAccessConversation($memberUser, $channel));
        $this->assertFalse(app(ChatAuthorization::class)->canAccessConversation($outsider, $channel));
        $this->assertFalse(app(ChatQueryService::class)->publicChannels($outsider)->whereKey($channel)->exists());

        $this->expectException(AuthorizationException::class);
        app(ChannelService::class)->addMembers($memberUser, $channel, [$outsider->employee->id]);
    }

    public function test_threads_reactions_and_pins_use_the_original_message_and_authorized_conversation(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $employee] = $this->employee(EmployeeRole::Staff);
        $channel = app(ChannelService::class)->create($owner, 'Dispatch', 'public', null, [$employee->id]);
        $root = app(ConversationMessageService::class)->send($owner, $channel, 'Shipment ready');
        $reply = app(ChatInteractionService::class)->reply($staff, $root, 'I will dispatch it');

        $this->assertSame($root->id, $reply->reply_to_message_id);
        $this->assertSame(1, $root->replies()->count());
        $this->assertTrue(app(ChatInteractionService::class)->toggleReaction($staff, $root, 'check'));
        $this->assertFalse(app(ChatInteractionService::class)->toggleReaction($staff, $root, 'check'));
        $this->assertTrue(app(ChatInteractionService::class)->togglePin($owner, $root));
        $this->assertDatabaseHas('conversation_message_pins', ['conversation_message_id' => $root->id]);
    }

    public function test_search_is_server_scoped_and_permission_denial_returns_no_results(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        [$staff, $employee] = $this->employee(EmployeeRole::Staff);
        [$outsider] = $this->employee(EmployeeRole::Staff);
        [$denied, $deniedEmployee] = $this->employee(EmployeeRole::Staff);
        EmployeePermissionOverride::query()->create([
            'employee_id' => $deniedEmployee->id,
            'permission_key' => ChatPermission::Search->value,
            'effect' => EmployeePermissionEffect::Deny,
            'granted_by_user_id' => $owner->id,
            'reason' => 'Focused test',
        ]);
        $channel = app(ChannelService::class)->create($owner, 'Sales', 'private', null, [$employee->id, $deniedEmployee->id]);
        app(ConversationMessageService::class)->send($owner, $channel, 'Confidential pipeline');
        $this->assertSame(1, app(ChatQueryService::class)->searchMessages($staff, 'pipeline')->count(), 'member search');
        $this->assertSame(0, app(ChatQueryService::class)->searchMessages($outsider, 'pipeline')->count(), 'outsider search');
        $this->assertSame(0, app(ChatQueryService::class)->searchMessages($denied, 'pipeline')->count(), 'permission-denied search');
    }

    public function test_livewire_channel_action_and_thread_panel_are_wired(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        $this->actingAs($owner);
        $component = Livewire::test(Chat::class)
            ->assertActionVisible('createChannel')
            ->callAction('createChannel', ['name' => 'Company News', 'visibility' => 'public', 'description' => 'Updates'])
            ->assertHasNoActionErrors()
            ->assertSet('filter', 'channels');
        $channel = Conversation::query()->where('slug', 'company-news')->sole();
        $message = app(ConversationMessageService::class)->send($owner, $channel, 'Welcome');
        $component->call('openThread', $message->id)
            ->assertSet('threadMessageId', $message->id)
            ->assertSee('Thread')
            ->assertSeeHtml('data-testid="chat-conversation-header"')
            ->assertSeeHtml('data-testid="chat-message-metadata"')
            ->assertSeeHtml('data-testid="chat-message-actions"')
            ->assertSeeHtml('data-testid="chat-composer"')
            ->assertSee('Add Members')
            ->assertSee('Edit Channel')
            ->assertSee('Remove Member');
    }

    public function test_channel_shape_and_duplicate_reaction_are_database_enforced(): void
    {
        [$owner] = $this->employee(EmployeeRole::Owner);
        $channel = app(ChannelService::class)->create($owner, 'Compliance', 'public');
        $message = app(ConversationMessageService::class)->send($owner, $channel, 'Check');
        DB::table('conversation_message_reactions')->insert([
            'conversation_message_id' => $message->id, 'employee_id' => $owner->employee->id,
            'reaction' => 'eyes', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->expectException(QueryException::class);
        DB::table('conversation_message_reactions')->insert([
            'conversation_message_id' => $message->id, 'employee_id' => $owner->employee->id,
            'reaction' => 'eyes', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{User, Employee} */
    private function employee(EmployeeRole $role): array
    {
        $employee = Employee::factory()->role($role)->create();

        return [$employee->user, $employee];
    }
}
