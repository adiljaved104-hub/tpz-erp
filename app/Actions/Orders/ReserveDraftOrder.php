<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;

class ReserveDraftOrder
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(Order $order, User $actor): Order
    {
        return $this->orders->reserveDraft($order, $actor);
    }
}
