<?php

namespace App\Actions\Orders;

use App\DTOs\Orders\SaveAndReserveOrderData;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\OrderService;

class SaveAndReserveOrder
{
    public function __construct(private readonly OrderService $orders) {}

    public function handle(SaveAndReserveOrderData $data, User $actor): Order
    {
        return $this->orders->saveAndReserve($data, $actor);
    }
}
