<?php

namespace App\Services\Chat;

use App\Enums\ChatPermission;
use App\Models\ConversationMessage;
use App\Models\ConversationMessagePin;
use App\Models\ConversationMessageReaction;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ChatInteractionService
{
    public const REACTIONS = ['thumbs_up', 'heart', 'celebrate', 'eyes', 'check'];

    public function __construct(
        private readonly ChatAuthorization $authorization,
        private readonly ConversationMessageService $messages,
    ) {}

    public function reply(User $actor, ConversationMessage $parent, string $body): ConversationMessage
    {
        $this->authorize($actor, ChatPermission::Thread, $parent);
        $root = $parent->replyTo ?: $parent;

        return $this->messages->send($actor, $root->conversation, $body, $root);
    }

    public function toggleReaction(User $actor, ConversationMessage $message, string $reaction): bool
    {
        $this->authorize($actor, ChatPermission::React, $message);
        if (! in_array($reaction, self::REACTIONS, true)) {
            throw ValidationException::withMessages(['reaction' => 'Select a supported reaction.']);
        }
        $existing = ConversationMessageReaction::query()->where([
            'conversation_message_id' => $message->id,
            'employee_id' => $actor->employee->id,
            'reaction' => $reaction,
        ])->first();
        if ($existing) {
            $existing->delete();

            return false;
        }
        ConversationMessageReaction::query()->create([
            'conversation_message_id' => $message->id,
            'employee_id' => $actor->employee->id,
            'reaction' => $reaction,
        ]);

        return true;
    }

    public function togglePin(User $actor, ConversationMessage $message): bool
    {
        $this->authorize($actor, ChatPermission::Pin, $message);
        $pin = ConversationMessagePin::query()->where('conversation_message_id', $message->id)->first();
        if ($pin) {
            $pin->delete();

            return false;
        }
        ConversationMessagePin::query()->create([
            'conversation_id' => $message->conversation_id,
            'conversation_message_id' => $message->id,
            'pinned_by_user_id' => $actor->id,
            'pinned_at' => now(),
        ]);

        return true;
    }

    private function authorize(User $actor, ChatPermission $permission, ConversationMessage $message): void
    {
        if (! $this->authorization->allows($actor, $permission)
            || ! $this->authorization->canAccessConversation($actor, $message->conversation)) {
            throw new AuthorizationException;
        }
    }
}
