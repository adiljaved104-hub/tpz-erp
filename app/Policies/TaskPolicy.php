<?php

namespace App\Policies;

use App\Enums\TaskPermission;
use App\Models\Task;
use App\Models\User;
use App\Services\Authorization\TaskAuthorization;

class TaskPolicy
{
    public function __construct(private readonly TaskAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, TaskPermission::View);
    }

    public function view(User $user, Task $task): bool
    {
        return $this->authorization->allows($user, TaskPermission::View, $task);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, TaskPermission::Create);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->authorization->allows($user, TaskPermission::Update, $task);
    }

    public function delete(): bool
    {
        return false;
    }
}
