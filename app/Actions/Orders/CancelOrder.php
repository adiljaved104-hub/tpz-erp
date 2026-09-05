<?php

namespace App\Actions\Orders;

use App\DTOs\Orders\CancelOrderData;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;

class CancelOrder
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(Order $order, CancelOrderData $data, User $actor): Order
    {
        return $this->orders->cancel($order, $data, $actor);
    }
}
