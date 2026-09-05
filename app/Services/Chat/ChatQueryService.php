<?php

namespace App\Services\Chat;

use App\Enums\ChatPermission;
use App\Enums\ComplaintPermission;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ChatAuthorization;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ChatQueryService
{
    public function __construct(private readonly ChatAuthorization $authorization) {}

    public function inbox(User $user, string $search = '', string|bool $filter = 'all'): Builder
    {
        $filter = is_bool($filter) ? ($filter ? 'archived' : 'all') : $filter;
        $employeeId = $user->employee->id;
        $query = $this->accessible($user)
            ->where('status', $filter === 'archived' ? ConversationStatus::Archived->value : ConversationStatus::Active->value)
            ->with(['team:id,name', 'participants.employee:id,name', 'latestMessage.sender:id,name'])
            ->select('conversations.*')
            ->selectSub(
                ConversationMessage::query()->selectRaw('MAX(created_at)')->whereColumn('conversation_id', 'conversations.id'),
                'latest_message_at',
            )
            ->selectSub(
                ConversationMessage::query()->selectRaw('COUNT(*)')
                    ->whereColumn('conversation_id', 'conversations.id')
                    ->where('sender_employee_id', '!=', $employeeId)
                    ->where('id', '>', DB::table('conversation_participants')
                        ->selectRaw('COALESCE(last_read_message_id, 0)')
                        ->whereColumn('conversation_id', 'conversations.id')
                        ->where('employee_id', $employeeId)
                        ->limit(1)),
                'unread_count',
            );

        $type = match ($filter) {
            'direct' => ConversationType::Direct,
            'teams' => ConversationType::Team,
            'contexts' => ConversationType::Context,
            'channels' => ConversationType::Channel,
            default => null,
        };
        if ($type instanceof ConversationType) {
            $query->where('type', $type->value);
        }

        if (($search = trim($search)) !== '') {
            $query->where(function (Builder $scope) use ($search): void {
                $scope->where('title', 'like', "%{$search}%")
                    ->orWhereHas('team', fn (Builder $team) => $team->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('participants.employee', fn (Builder $employee) => $employee->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('messages', fn (Builder $message) => $message->where('body', 'like', "%{$search}%"));
            });
        }

        return $query->orderByDesc('latest_message_at')->orderByDesc('updated_at');
    }

    public function accessible(User $user): Builder
    {
        if (! $this->authorization->allows($user, ChatPermission::View)) {
            return Conversation::query()->whereRaw('1 = 0');
        }

        return Conversation::query()->where(function (Builder $query) use ($user): void {
            if ($this->authorization->allows($user, ChatPermission::Direct)) {
                $query->orWhere(fn (Builder $direct) => $direct
                    ->where('type', ConversationType::Direct->value)
                    ->whereHas('participants', fn (Builder $participant) => $participant
                        ->where('employee_id', $user->employee->id)->whereNull('left_at')));
            }
            if ($this->authorization->allows($user, ChatPermission::Team)) {
                $query->orWhere(fn (Builder $team) => $team
                    ->where('type', ConversationType::Team->value)
                    ->when(! in_array($user->employee->role, [EmployeeRole::Owner, EmployeeRole::Admin], true), fn (Builder $own) => $own->where('team_id', $user->employee->team_id)));
            }
            if ($this->authorization->allows($user, ChatPermission::Context)) {
                $query->orWhere(fn (Builder $context) => $context
                    ->where('type', ConversationType::Context->value)
                    ->where(function (Builder $sources) use ($user): void {
                        $this->addContextScopes($sources, $user);
                    }));
            }
            $query->orWhere(fn (Builder $channel) => $channel
                ->where('type', ConversationType::Channel->value)
                ->when(! $this->authorization->allows($user, ChatPermission::ChannelManage), fn (Builder $member) => $member
                    ->whereHas('participants', fn (Builder $participant) => $participant
                        ->where('employee_id', $user->employee->id)->whereNull('left_at'))));
        });
    }

    public function publicChannels(User $user, string $search = ''): Builder
    {
        if (! $this->authorization->allows($user, ChatPermission::ChannelJoin)) {
            return Conversation::query()->whereRaw('1 = 0');
        }

        return Conversation::query()->where('type', ConversationType::Channel->value)
            ->where('visibility', 'public')->where('status', ConversationStatus::Active->value)
            ->whereDoesntHave('participants', fn (Builder $participant) => $participant
                ->where('employee_id', $user->employee->id)->whereNull('left_at'))
            ->when(trim($search) !== '', fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->where('title', 'like', '%'.trim($search).'%')->orWhere('description', 'like', '%'.trim($search).'%')))
            ->withCount(['participants' => fn (Builder $participant) => $participant->whereNull('left_at')])
            ->orderBy('title');
    }

    public function searchMessages(User $user, string $search, ?int $senderEmployeeId = null, ?string $from = null, ?string $to = null): Builder
    {
        if (! $this->authorization->allows($user, ChatPermission::Search) || trim($search) === '') {
            return ConversationMessage::query()->whereRaw('1 = 0');
        }

        return ConversationMessage::query()
            ->joinSub($this->accessible($user)->select('conversations.id'), 'searchable_conversations', fn ($join) => $join->on('searchable_conversations.id', '=', 'conversation_messages.conversation_id'))
            ->where('body', 'like', '%'.trim($search).'%')
            ->when($senderEmployeeId, fn (Builder $query) => $query->where('sender_employee_id', $senderEmployeeId))
            ->when($from, fn (Builder $query) => $query->whereDate('conversation_messages.created_at', '>=', $from))
            ->when($to, fn (Builder $query) => $query->whereDate('conversation_messages.created_at', '<=', $to))
            ->with(['sender:id,name', 'conversation.team:id,name', 'conversation.participants.employee:id,name'])
            ->select('conversation_messages.*')->latest('conversation_messages.id');
    }

    public function totalUnread(User $user): int
    {
        $employeeId = $user->employee->id;

        return ConversationMessage::query()
            ->joinSub($this->accessible($user)->select('conversations.id'), 'accessible_conversations', fn ($join) => $join->on('accessible_conversations.id', '=', 'conversation_messages.conversation_id'))
            ->join('conversations', 'conversations.id', '=', 'conversation_messages.conversation_id')
            ->leftJoin('conversation_participants as chat_reader', function ($join) use ($employeeId): void {
                $join->on('chat_reader.conversation_id', '=', 'conversation_messages.conversation_id')
                    ->where('chat_reader.employee_id', $employeeId);
            })
            ->where('conversations.status', ConversationStatus::Active->value)
            ->where('conversation_messages.sender_employee_id', '!=', $employeeId)
            ->whereRaw('conversation_messages.id > COALESCE(chat_reader.last_read_message_id, 0)')
            ->count();
    }

    /** @return array<int, string> */
    public function directEmployeeOptions(User $user): array
    {
        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')->where('id', '!=', $user->employee->id)
            ->orderBy('name')->get()->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->allows($employee->user, ChatPermission::View)
                && $this->authorization->allows($employee->user, ChatPermission::Direct))
            ->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public function channelMemberOptions(User $user, ?Conversation $channel = null): array
    {
        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')
            ->when($channel, fn (Builder $query) => $query->whereDoesntHave('conversationParticipations', fn (Builder $participant) => $participant
                ->where('conversation_id', $channel->id)->whereNull('left_at')))
            ->orderBy('name')->get()->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->allows($employee->user, ChatPermission::View))
            ->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public function currentChannelMemberOptions(Conversation $channel): array
    {
        return $channel->participants()->whereNull('left_at')->with('employee:id,name')->get()
            ->pluck('employee.name', 'employee_id')->all();
    }

    /** @return array<int, string> */
    public function mentionOptions(User $user, Conversation $conversation): array
    {
        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')->whereKeyNot($user->employee->id)
            ->orderBy('name')->get()->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->canAccessConversation($employee->user, $conversation))
            ->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    public function teamOptions(User $user): array
    {
        return Team::query()->where('status', true)->orderBy('name')->get()
            ->filter(fn (Team $team): bool => $this->authorization->canAccessTeam($user, $team))
            ->pluck('name', 'id')->all();
    }

    private function addContextScopes(Builder $query, User $user): void
    {
        $query->whereRaw('1 = 0');
        if (app(OrderAuthorization::class)->allows($user, OrderPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'order')->whereIn('context_id', $this->scopedOrders($user)));
        }
        if (app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'customer_return')->whereIn('context_id', CustomerReturn::query()->select('id')->whereIn('order_id', $this->scopedOrders($user))));
        }
        if (app(TaskAuthorization::class)->allows($user, TaskPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'task')->whereIn('context_id', app(TaskAuthorization::class)->scopeQuery(Task::query()->select('id'), $user)));
        }
        if (app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'safet_claim')->whereIn('context_id', app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query()->select('id'), $user)));
        }
        if (app(ComplaintAuthorization::class)->allows($user, ComplaintPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'complaint')->whereIn('context_id', app(ComplaintAuthorization::class)->scopeQuery(Complaint::query()->select('id'), $user)));
        }
        if (app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View)) {
            $query->orWhere(fn (Builder $q) => $q->where('context_type', 'warranty_repair')->whereIn('context_id', app(WarrantyRepairAuthorization::class)->scopeQuery(WarrantyRepair::query()->select('id'), $user)));
        }
    }

    private function scopedOrders(User $user): Builder
    {
        $scope = app(OrderResponsibilityScopeService::class);
        $query = Order::query()->select('id');
        if ($scope->requiresScope($user)) {
            $query->whereHas('items');
        }

        return $scope->applyOrders($query, $user);
    }
}
