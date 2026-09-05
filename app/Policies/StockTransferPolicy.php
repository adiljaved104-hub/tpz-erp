<?php

namespace App\Policies;

use App\Enums\StockTransferPermission;
use App\Models\StockTransfer;
use App\Models\User;
use App\Services\Authorization\StockTransferAuthorization;

class StockTransferPolicy
{
    public function __construct(private readonly StockTransferAuthorization $authorization) {}

    public function viewAny(User $user): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::View);
    }

    public function view(User $user, StockTransfer $transfer): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::View, $transfer);
    }

    public function create(User $user): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::Create);
    }

    public function dispatch(User $user, StockTransfer $transfer): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::Dispatch, $transfer);
    }

    public function receive(User $user, StockTransfer $transfer): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::Receive, $transfer);
    }

    public function cancel(User $user, StockTransfer $transfer): bool
    {
        return $this->authorization->allows($user, StockTransferPermission::Cancel, $transfer);
    }

    public function delete(User $user, StockTransfer $transfer): bool
    {
        return false;
    }
}
