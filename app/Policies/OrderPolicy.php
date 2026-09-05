<?php

namespace App\Policies;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;

class OrderPolicy
{
    public function __construct(private readonly OrderAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, OrderPermission::View);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->authorization->allows($user, OrderPermission::View, $order);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, OrderPermission::Create);
    }

    public function update(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Draft
            && $this->authorization->allows($user, OrderPermission::UpdateDraft, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->authorization->allows($user, OrderPermission::Cancel, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
