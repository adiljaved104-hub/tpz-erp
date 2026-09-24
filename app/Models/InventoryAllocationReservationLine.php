<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InventoryAllocationReservationLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }
}
