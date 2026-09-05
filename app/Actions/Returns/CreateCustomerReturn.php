<?php

namespace App\Actions\Returns;

use App\DTOs\Returns\CreateCustomerReturnData;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Returns\CustomerReturnService;

class CreateCustomerReturn
{
    public function __construct(private readonly CustomerReturnService $service) {}

    public function handle(CreateCustomerReturnData $data, User $actor): CustomerReturn
    {
        return $this->service->create($data, $actor);
    }
}
