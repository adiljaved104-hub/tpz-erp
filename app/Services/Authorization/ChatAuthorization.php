<?php

namespace App\Services\Authorization;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Enums\ChatPermission;
use App\Enums\ComplaintPermission;
use App\Enums\ConversationType;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\CustomerReturn;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class ChatAuthorization
{
    public function __construct(private readonly EmployeePermissionOverrideResolver $overrides) {}

    public function roleDefault(User $user, ChatPermission $permission): bool
    {
        return match ($user->employee?->role) {
            EmployeeRole::Owner, EmployeeRole::Admin => true,
            EmployeeRole::Manager, EmployeeRole::Staff => in_array($permission, [
                ChatPermission::View,
                ChatPermission::Direct,
                ChatPermission::Team,
                ChatPermission::Context,
                ChatPermission::ChannelJoin,
                ChatPermission::Thread,
                ChatPermission::React,
                ChatPermission::Search,
            ], true),
            default => false,
        };
    }

    public function allows(User $user, ChatPermission $permission): bool
    {
        if ($user->employee?->status !== true) {
            return false;
        }

        $default = $this->roleDefault($user, $permission);

        return $user->employee->role === EmployeeRole::Owner
            ? $default
            : ($this->overrides->decision($user, $permission->value) ?? $default);
    }

    public function canAccessConversation(User $user, Conversation $conversation): bool
    {
        if (! $this->allows($user, ChatPermission::View)) {
            return false;
        }

        return match ($conversation->type) {
            ConversationType::Direct => $this->allows($user, ChatPermission::Direct)
                && $conversation->participants()->where('employee_id', $user->employee->id)->whereNull('left_at')->exists(),
            ConversationType::Team => $this->allows($user, ChatPermission::Team)
                && $conversation->team !== null
                && $this->canAccessTeam($user, $conversation->team),
            ConversationType::Context => $this->allows($user, ChatPermission::Context)
                && $this->canViewContext($user, $conversation->context),
            ConversationType::Channel => $this->allows($user, ChatPermission::ChannelManage)
                || $conversation->participants()->where('employee_id', $user->employee->id)->whereNull('left_at')->exists(),
        };
    }

    public function canAccessTeam(User $user, Team $team): bool
    {
        if (! $this->allows($user, ChatPermission::Team) || ! (bool) $team->status) {
            return false;
        }

        if (in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)) {
            return true;
        }

        return $user->employee?->team_id === $team->id;
    }

    public function canViewContext(User $user, ?Model $context): bool
    {
        return match (true) {
            $context instanceof Task => app(TaskAuthorization::class)->allows($user, TaskPermission::View, $context),
            $context instanceof Order => app(OrderAuthorization::class)->allows($user, OrderPermission::View, $context),
            $context instanceof CustomerReturn => app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View, $context),
            $context instanceof SafetClaim => app(SafetClaimAuthorization::class)->allows($user, SafetClaimPermission::View, $context),
            $context instanceof Complaint => app(ComplaintAuthorization::class)->allows($user, ComplaintPermission::View, $context),
            $context instanceof WarrantyRepair => app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::View, $context),
            default => false,
        };
    }

    public function authorizeConversation(User $user, Conversation $conversation): void
    {
        if (! $this->canAccessConversation($user, $conversation)) {
            throw new AuthorizationException;
        }
    }
}
