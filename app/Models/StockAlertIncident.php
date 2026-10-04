<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockAlertIncident extends Model
{
    public const LOW_STOCK = 'low_stock';

    public const OUT_OF_STOCK = 'out_of_stock';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_sellable_quantity' => 'integer',
            'current_sellable_quantity' => 'integer',
            'opened_at' => 'immutable_datetime',
            'last_evaluated_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(StockAlertIncidentRecipient::class);
    }
}
