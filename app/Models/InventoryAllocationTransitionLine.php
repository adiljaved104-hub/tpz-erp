<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAllocationTransitionLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'remaining_quantity' => 'integer'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class);
    }
}
