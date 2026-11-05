<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferMarketplaceLoss extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'evidence' => 'array', 'claim_amount' => 'decimal:2', 'reimbursed_amount' => 'decimal:2', 'resolved_at' => 'immutable_datetime', 'investigating_at' => 'immutable_datetime', 'claim_submitted_at' => 'immutable_datetime', 'closed_at' => 'immutable_datetime'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }
}
