<?php

namespace App\Actions\Returns;

use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Returns\CustomerReturnService;

class CancelCustomerReturn
{
    public function __construct(private readonly CustomerReturnService $service) {}

    public function handle(CustomerReturn $return, string $reason, User $actor): CustomerReturn
    {
        return $this->service->cancel($return, $reason, $actor);
    }
}
