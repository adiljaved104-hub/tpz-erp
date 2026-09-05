<?php

namespace App\Services\Chat;

use App\Enums\ChannelVisibility;
use App\Enums\ChatPermission;
use App\Enums\ConversationStatus;
use App\Enums\ConversationType;
use App\Models\Conversation;
use App\Models\Employee;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\ChatAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ChannelService
{
    public function __construct(
        private readonly ChatAuthorization $authorization,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** @param array<int, int|string> $memberIds */
    public function create(User $actor, string $name, ChannelVisibility|string $visibility, ?string $description = null, array $memberIds = []): Conversation
    {
        $this->authorize($actor, ChatPermission::ChannelCreate);
        $visibility = $visibility instanceof ChannelVisibility ? $visibility : ChannelVisibility::from($visibility);
        $name = trim($name);
        $slug = Str::slug($name);
        if ($name === '' || $slug === '' || mb_strlen($name) > 80 || mb_strlen($slug) > 100) {
            throw ValidationException::withMessages(['name' => 'Enter a channel name of no more than 80 characters.']);
        }
        if (Conversation::query()->where('slug', $slug)->exists()) {
            throw ValidationException::withMessages(['name' => 'A channel with this name already exists.']);
        }
        $description = blank($description) ? null : trim((string) $description);
        if ($description !== null && mb_strlen($description) > 500) {
            throw ValidationException::withMessages(['description' => 'Channel descriptions may not exceed 500 characters.']);
        }
        $members = $this->eligibleMembers($memberIds)->push($actor->employee)->unique('id');

        return DB::transaction(function () use ($actor, $name, $slug, $description, $visibility, $members): Conversation {
            $channel = Conversation::query()->create([
                'type' => ConversationType::Channel,
                'title' => $name,
                'slug' => $slug,
                'description' => $description,
                'visibility' => $visibility,
                'status' => ConversationStatus::Active,
                'created_by_user_id' => $actor->id,
            ]);
            foreach ($members as $employee) {
                $channel->participants()->create(['employee_id' => $employee->id, 'joined_at' => now()]);
            }
            $this->activityLogger->log('chat.channel_created', $actor, $channel, [
                'visibility' => $visibility->value,
                'member_count' => $members->count(),
            ]);

            return $channel->fresh(['participants.employee']);
        });
    }

    public function join(User $actor, Conversation $channel): void
    {
        $this->authorize($actor, ChatPermission::ChannelJoin);
        $this->assertChannel($channel);
        if ($channel->visibility !== ChannelVisibility::Public || $channel->status !== ConversationStatus::Active) {
            throw new AuthorizationException;
        }
        $participant = $channel->participants()->firstOrNew(['employee_id' => $actor->employee->id]);
        $participant->fill(['joined_at' => now(), 'left_at' => null])->save();
    }

    public function update(User $actor, Conversation $channel, string $name, ChannelVisibility|string $visibility, ?string $description = null): Conversation
    {
        $this->authorizeChannelManagement($actor, $channel);
        $visibility = $visibility instanceof ChannelVisibility ? $visibility : ChannelVisibility::from($visibility);
        $name = trim($name);
        $slug = Str::slug($name);
        if ($name === '' || $slug === '' || mb_strlen($name) > 80 || Conversation::query()->where('slug', $slug)->whereKeyNot($channel->id)->exists()) {
            throw ValidationException::withMessages(['name' => 'Enter a unique channel name of no more than 80 characters.']);
        }
        $description = blank($description) ? null : trim((string) $description);
        if ($description !== null && mb_strlen($description) > 500) {
            throw ValidationException::withMessages(['description' => 'Channel descriptions may not exceed 500 characters.']);
        }
        $channel->update(['title' => $name, 'slug' => $slug, 'visibility' => $visibility, 'description' => $description]);
        $this->activityLogger->log('chat.channel_updated', $actor, $channel, ['visibility' => $visibility->value]);

        return $channel->refresh();
    }

    public function leave(User $actor, Conversation $channel): void
    {
        $this->assertChannel($channel);
        $participant = $channel->participants()->where('employee_id', $actor->employee->id)->whereNull('left_at')->first();
        if ($participant === null) {
            throw new AuthorizationException;
        }
        $participant->update(['left_at' => now()]);
    }

    /** @param array<int, int|string> $memberIds */
    public function addMembers(User $actor, Conversation $channel, array $memberIds): void
    {
        $this->authorizeChannelManagement($actor, $channel);
        $members = $this->eligibleMembers($memberIds);
        DB::transaction(function () use ($actor, $channel, $members): void {
            foreach ($members as $employee) {
                $participant = $channel->participants()->firstOrNew(['employee_id' => $employee->id]);
                $participant->fill(['joined_at' => now(), 'left_at' => null])->save();
            }
            $this->activityLogger->log('chat.channel_members_added', $actor, $channel, ['employee_ids' => $members->pluck('id')->all()]);
        });
    }

    public function removeMember(User $actor, Conversation $channel, Employee $employee): void
    {
        $this->authorizeChannelManagement($actor, $channel);
        $participant = $channel->participants()->where('employee_id', $employee->id)->whereNull('left_at')->firstOrFail();
        $participant->update(['left_at' => now()]);
        $this->activityLogger->log('chat.channel_member_removed', $actor, $channel, ['employee_id' => $employee->id]);
    }

    public function archive(User $actor, Conversation $channel): void
    {
        $this->authorize($actor, ChatPermission::ChannelArchive);
        $this->assertChannel($channel);
        if ($channel->status === ConversationStatus::Archived) {
            return;
        }
        $channel->update(['status' => ConversationStatus::Archived, 'archived_at' => now(), 'archived_by_user_id' => $actor->id]);
        $this->activityLogger->log('chat.channel_archived', $actor, $channel);
    }

    private function authorizeChannelManagement(User $actor, Conversation $channel): void
    {
        $this->authorize($actor, ChatPermission::ChannelManage);
        $this->assertChannel($channel);
    }

    private function authorize(User $actor, ChatPermission $permission): void
    {
        if (! $this->authorization->allows($actor, ChatPermission::View) || ! $this->authorization->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    private function assertChannel(Conversation $channel): void
    {
        if ($channel->type !== ConversationType::Channel) {
            throw ValidationException::withMessages(['channel' => 'Select a valid channel.']);
        }
    }

    /** @param array<int, int|string> $ids */
    private function eligibleMembers(array $ids)
    {
        $members = Employee::query()->with('user')->whereKey(array_unique(array_map('intval', $ids)))
            ->where('status', true)->whereNotNull('user_id')->get();
        if ($members->count() !== count(array_unique(array_map('intval', $ids)))
            || $members->contains(fn (Employee $employee): bool => ! $this->authorization->allows($employee->user, ChatPermission::View))) {
            throw ValidationException::withMessages(['member_ids' => 'One or more selected Employees are not eligible for Chat.']);
        }

        return $members;
    }
}
