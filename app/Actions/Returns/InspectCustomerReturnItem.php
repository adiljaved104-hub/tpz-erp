<?php

namespace App\Actions\Returns;

use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnItem;
use App\Models\User;
use App\Services\Returns\CustomerReturnService;

class InspectCustomerReturnItem
{
    public function __construct(private readonly CustomerReturnService $service) {}

    public function handle(CustomerReturnItem $item, InspectCustomerReturnItemData $data, User $actor): CustomerReturn
    {
        return $this->service->inspect($item, $data, $actor);
    }
}
