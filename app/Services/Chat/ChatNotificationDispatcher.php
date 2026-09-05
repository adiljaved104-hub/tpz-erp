<?php

namespace App\Services\Chat;

use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Employee;
use App\Models\User;
use App\Notifications\ChatDatabaseNotification;
use App\Services\Authorization\ChatAuthorization;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChatNotificationDispatcher
{
    public function __construct(private readonly ChatAuthorization $authorization) {}

    /** @param array<int, int|string> $mentionEmployeeIds */
    public function dispatch(ConversationMessage $message, User $sender, array $mentionEmployeeIds): void
    {
        $conversation = $message->conversation;
        $mentions = collect($mentionEmployeeIds)->map(fn ($id): int => (int) $id)->unique()->values();
        $eligible = $this->validateMentions($conversation, $sender, $mentionEmployeeIds);

        $recipients = $this->normalRecipients($conversation, $sender)->keyBy('id');
        foreach ($eligible->whereIn('id', $mentions) as $employee) {
            $conversation->participants()->firstOrCreate(['employee_id' => $employee->id], ['joined_at' => now()]);
            $recipients->put($employee->id, $employee);
        }

        foreach ($recipients as $employee) {
            $mentioned = $mentions->contains($employee->id);
            $type = $mentioned ? 'chat.mention' : match ($conversation->type) {
                ConversationType::Direct => 'chat.direct_message',
                ConversationType::Team => 'chat.team_message',
                ConversationType::Context => 'chat.context_message',
                ConversationType::Channel => 'chat.channel_message',
            };
            $employee->user?->notify(new ChatDatabaseNotification((string) Str::uuid(), $type, [
                'title' => $mentioned ? $sender->employee->name.' mentioned you in Chat' : $this->title($conversation),
                // The stored fallback must never retain structured record tokens. The
                // inbox presenter resolves current authorized labels at render time.
                'message' => Str::limit(trim((string) preg_replace(
                    '/\[\[erp-record:[a-z_]+:\d+\]\]/',
                    'Business record',
                    $message->body,
                )), 140),
                'target_type' => 'conversation',
                'target_id' => $conversation->id,
                'message_id' => $message->id,
            ]));
        }
    }

    /**
     * @param  array<int, int|string>  $mentionEmployeeIds
     * @return Collection<int, Employee>
     */
    public function validateMentions(Conversation $conversation, User $sender, array $mentionEmployeeIds): Collection
    {
        $mentions = collect($mentionEmployeeIds)->map(fn ($id): int => (int) $id)->unique()->values();
        $eligible = $this->eligibleEmployees($conversation, $sender);
        if ($mentions->diff($eligible->pluck('id'))->isNotEmpty()) {
            throw ValidationException::withMessages(['mention_employee_ids' => 'One or more mentioned Employees cannot access this conversation.']);
        }

        return $eligible;
    }

    /** @return Collection<int, Employee> */
    private function eligibleEmployees(Conversation $conversation, User $sender): Collection
    {
        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')
            ->where('id', '!=', $sender->employee->id)->get()
            ->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->canAccessConversation($employee->user, $conversation))->values();
    }

    /** @return Collection<int, Employee> */
    private function normalRecipients(Conversation $conversation, User $sender): Collection
    {
        $query = match ($conversation->type) {
            ConversationType::Direct, ConversationType::Context, ConversationType::Channel => Employee::query()->whereHas('conversationParticipations', fn ($participant) => $participant->where('conversation_id', $conversation->id)->whereNull('left_at')),
            ConversationType::Team => Employee::query()->where('team_id', $conversation->team_id),
        };

        return $query->with('user')->where('status', true)->whereNotNull('user_id')
            ->where('id', '!=', $sender->employee->id)->get()
            ->filter(fn (Employee $employee): bool => $employee->user !== null
                && $this->authorization->canAccessConversation($employee->user, $conversation))->values();
    }

    private function title(Conversation $conversation): string
    {
        return match ($conversation->type) {
            ConversationType::Direct => 'New direct message',
            ConversationType::Team => 'New message in '.($conversation->team?->name ?? 'Team Chat'),
            ConversationType::Context => 'New message in '.($conversation->title ?? 'Work Discussion'),
            ConversationType::Channel => 'New message in #'.ltrim((string) $conversation->slug, '#'),
        };
    }
}
