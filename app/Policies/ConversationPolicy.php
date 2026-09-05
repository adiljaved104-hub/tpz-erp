<?php

namespace App\Policies;

use App\Enums\ChatPermission;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Authorization\ChatAuthorization;

class ConversationPolicy
{
    public function __construct(private readonly ChatAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ChatPermission::View);
    }

    public function view(User $user, Conversation $conversation): bool
    {
        return $this->authorization->canAccessConversation($user, $conversation);
    }

    public function delete(): bool
    {
        return false;
    }
}
