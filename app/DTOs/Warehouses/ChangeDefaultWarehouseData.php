<?php

namespace App\DTOs\Warehouses;

final readonly class ChangeDefaultWarehouseData
{
    public function __construct(public string $reason) {}
}
