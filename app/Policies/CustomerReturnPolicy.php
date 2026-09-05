<?php

namespace App\Policies;

use App\Enums\CustomerReturnPermission;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;

class CustomerReturnPolicy
{
    public function __construct(private readonly CustomerReturnAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, CustomerReturnPermission::View);
    }

    public function view(User $user, CustomerReturn $return): bool
    {
        return $this->authorization->allows($user, CustomerReturnPermission::View, $return);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, CustomerReturnPermission::Create);
    }

    public function delete(User $user, CustomerReturn $return): bool
    {
        return false;
    }
}
