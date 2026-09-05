<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;

class FulfillOrder
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(Order $order, string $idempotencyKey, User $actor): Order
    {
        return $this->orders->fulfill($order, $idempotencyKey, $actor);
    }
}
