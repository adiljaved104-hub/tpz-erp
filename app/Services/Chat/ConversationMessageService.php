<?php

namespace App\Services\Chat;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationParticipant;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConversationMessageService
{
    public const MAX_BODY_LENGTH = 5000;

    public function __construct(
        private readonly ChatAuthorization $authorization,
        private readonly ChatNotificationDispatcher $notifications,
    ) {}

    /** @param array<int, int|string> $mentionEmployeeIds */
    public function send(User $actor, Conversation $conversation, string $body, ?ConversationMessage $replyTo = null, array $mentionEmployeeIds = []): ConversationMessage
    {
        $this->authorization->authorizeConversation($actor, $conversation);
        if ($conversation->status !== ConversationStatus::Active) {
            throw ValidationException::withMessages(['conversation' => 'Archived conversations cannot receive messages.']);
        }

        $body = trim($body);
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Enter a message.']);
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages(['body' => 'Messages may not exceed 5,000 characters.']);
        }
        if ($body !== strip_tags($body)) {
            throw ValidationException::withMessages(['body' => 'Messages must be plain text.']);
        }
        if ($replyTo !== null && $replyTo->conversation_id !== $conversation->id) {
            throw ValidationException::withMessages(['reply_to_message_id' => 'The reply must reference a message in this conversation.']);
        }

        $this->notifications->validateMentions($conversation, $actor, $mentionEmployeeIds);

        $message = DB::transaction(function () use ($actor, $conversation, $body, $replyTo): ConversationMessage {
            $this->ensureParticipant($actor, $conversation);

            return $conversation->messages()->create([
                'sender_employee_id' => $actor->employee->id,
                'reply_to_message_id' => $replyTo?->id,
                'body' => $body,
            ]);
        });

        $this->notifications->dispatch($message->load('conversation.team'), $actor, $mentionEmployeeIds);

        return $message;
    }

    public function markRead(User $actor, Conversation $conversation, ConversationMessage $message): ConversationParticipant
    {
        $this->authorization->authorizeConversation($actor, $conversation);
        if ($message->conversation_id !== $conversation->id) {
            throw ValidationException::withMessages(['message' => 'The read marker must reference this conversation.']);
        }

        $participant = $this->ensureParticipant($actor, $conversation);
        if ($participant->last_read_message_id === null || $message->id > $participant->last_read_message_id) {
            $participant->update(['last_read_message_id' => $message->id]);
        }

        return $participant->refresh();
    }

    public function unreadCount(User $actor, Conversation $conversation): int
    {
        $this->authorization->authorizeConversation($actor, $conversation);
        $participant = $conversation->participants()->where('employee_id', $actor->employee->id)->first();

        return $conversation->messages()
            ->when($participant?->last_read_message_id, fn ($query, int $messageId) => $query->where('id', '>', $messageId))
            ->where('sender_employee_id', '!=', $actor->employee->id)
            ->count();
    }

    private function ensureParticipant(User $actor, Conversation $conversation): ConversationParticipant
    {
        return $conversation->participants()->firstOrCreate(
            ['employee_id' => $actor->employee->id],
            ['joined_at' => now()],
        );
    }
}
