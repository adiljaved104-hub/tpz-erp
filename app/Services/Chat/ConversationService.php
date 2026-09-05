<?php

namespace App\Services\Chat;

use App\Enums\ChatPermission;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Complaint;
use App\Models\Conversation;
use App\Models\CustomerReturn;
use App\Models\Employee;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\ActivityLogger;
use App\Services\Authorization\ChatAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationService
{
    /** @var array<class-string<Model>> */
    private const ALLOWED_CONTEXT_MODELS = [
        Task::class,
        Order::class,
        CustomerReturn::class,
        SafetClaim::class,
        Complaint::class,
        WarrantyRepair::class,
    ];

    public function __construct(
        private readonly ChatAuthorization $authorization,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function direct(User $actor, Employee $other): Conversation
    {
        $this->authorizePermission($actor, ChatPermission::Direct);
        $actorEmployee = $actor->employee;
        if ($other->status !== true || $other->user_id === null || $other->is($actorEmployee)) {
            throw ValidationException::withMessages(['employee_id' => 'Select another active Employee with ERP access.']);
        }

        $ids = [$actorEmployee->id, $other->id];
        sort($ids, SORT_NUMERIC);
        $fingerprint = hash('sha256', implode(':', $ids));

        return DB::transaction(function () use ($actor, $ids, $fingerprint): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['direct_fingerprint' => $fingerprint],
                ['type' => ConversationType::Direct, 'status' => ConversationStatus::Active, 'created_by_user_id' => $actor->id],
            );

            foreach ($ids as $employeeId) {
                $conversation->participants()->firstOrCreate(
                    ['employee_id' => $employeeId],
                    ['joined_at' => now()],
                );
            }

            $this->logCreation($conversation, $actor);

            return $conversation->fresh(['participants']);
        });
    }

    public function team(User $actor, Team $team, ?string $title = null): Conversation
    {
        $this->authorizePermission($actor, ChatPermission::Team);
        if (! $this->authorization->canAccessTeam($actor, $team)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $team, $title): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['team_id' => $team->id],
                [
                    'type' => ConversationType::Team,
                    'title' => $this->nullableTitle($title),
                    'status' => ConversationStatus::Active,
                    'created_by_user_id' => $actor->id,
                ],
            );
            $this->logCreation($conversation, $actor);

            return $conversation;
        });
    }

    public function context(User $actor, Model $context, ?string $title = null): Conversation
    {
        $this->authorizePermission($actor, ChatPermission::Context);
        if (! in_array($context::class, self::ALLOWED_CONTEXT_MODELS, true)) {
            throw ValidationException::withMessages(['context' => 'This record type cannot be linked to Chat.']);
        }
        if (! $this->authorization->canViewContext($actor, $context)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $context, $title): Conversation {
            $conversation = Conversation::query()->firstOrCreate(
                ['context_type' => $context->getMorphClass(), 'context_id' => $context->getKey()],
                [
                    'type' => ConversationType::Context,
                    'title' => $this->nullableTitle($title),
                    'status' => ConversationStatus::Active,
                    'created_by_user_id' => $actor->id,
                ],
            );
            $conversation->participants()->firstOrCreate(
                ['employee_id' => $actor->employee->id],
                ['joined_at' => now()],
            );
            $this->logCreation($conversation, $actor);

            return $conversation->fresh(['context']);
        });
    }

    public function archive(User $actor, Conversation $conversation): Conversation
    {
        if (! $this->authorization->allows($actor, ChatPermission::Manage)
            || ! $this->authorization->canAccessConversation($actor, $conversation)) {
            throw new AuthorizationException;
        }
        if ($conversation->status === ConversationStatus::Archived) {
            return $conversation;
        }

        return DB::transaction(function () use ($actor, $conversation): Conversation {
            $conversation->update([
                'status' => ConversationStatus::Archived,
                'archived_by_user_id' => $actor->id,
                'archived_at' => now(),
            ]);
            $this->activityLogger->log('chat.conversation_archived', $actor, $conversation);

            return $conversation->refresh();
        });
    }

    private function authorizePermission(User $actor, ChatPermission $permission): void
    {
        if (! $this->authorization->allows($actor, ChatPermission::View) || ! $this->authorization->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function logCreation(Conversation $conversation, User $actor): void
    {
        if (! $conversation->wasRecentlyCreated) {
            return;
        }

        $this->activityLogger->log(
            'chat.conversation_created',
            $actor,
            $conversation,
            ['conversation_type' => $conversation->type->value],
        );
    }

    private function nullableTitle(?string $title): ?string
    {
        $title = $title === null ? null : trim($title);

        if ($title !== null && mb_strlen($title) > 255) {
            throw ValidationException::withMessages(['title' => 'Conversation titles may not exceed 255 characters.']);
        }

        return $title === '' ? null : $title;
    }
}
