<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferReservation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'released_at' => 'immutable_datetime', 'fulfilled_at' => 'immutable_datetime'];
    }

    public function transfer(): BelongsTo { return $this->belongsTo(StockTransfer::class, 'stock_transfer_id'); }
    public function item(): BelongsTo { return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id'); }
    public function reservation(): BelongsTo { return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id'); }
}
