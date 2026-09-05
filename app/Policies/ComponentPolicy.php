<?php

namespace App\Policies;

use App\Enums\ComponentPermission;
use App\Models\Component;
use App\Models\User;
use App\Services\Authorization\ComponentAuthorization;

class ComponentPolicy
{
    public function __construct(private readonly ComponentAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, ComponentPermission::View);
    }

    public function view(User $user, Component $component): bool
    {
        return $this->authorization->allows($user, ComponentPermission::View, $component);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, ComponentPermission::Create);
    }

    public function update(User $user, Component $component): bool
    {
        return $this->authorization->allows($user, ComponentPermission::Update, $component);
    }

    public function delete(User $user, Component $component): bool
    {
        return false;
    }
}
