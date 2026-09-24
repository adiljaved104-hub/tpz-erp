<?php

namespace App\Models;

use App\Enums\InventoryAllocationMode;
use App\Enums\InventoryAllocationPolicy;
use Illuminate\Database\Eloquent\Model;

class InventoryAllocationSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['enforcement_mode' => InventoryAllocationMode::class, 'default_policy' => InventoryAllocationPolicy::class];
    }
}
