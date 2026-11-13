<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockRequestExecutionLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Stock Request execution lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Stock Request execution lines cannot be deleted.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockRequestItem::class, 'stock_request_item_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(StockRequestSourceLine::class, 'stock_request_source_line_id');
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'source_account_id');
    }

    public function destinationAccount(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'destination_account_id');
    }
}
