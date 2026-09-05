<?php

namespace App\Actions\Returns;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Returns\CustomerReturnService;

class ReceiveCustomerReturn
{
    public function __construct(private readonly CustomerReturnService $service) {}

    public function handle(CustomerReturn $return, User $actor): CustomerReturn
    {
        return $this->service->receive($return, $actor);
    }
}
