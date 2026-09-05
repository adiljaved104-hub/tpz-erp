<?php

namespace App\Models;

use App\Exceptions\InvalidStockTransferTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransferItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'dispatched_quantity' => 'integer', 'received_quantity' => 'integer', 'returned_quantity' => 'integer', 'lost_quantity' => 'integer', 'dispatch_unit_cost' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new InvalidStockTransferTransitionException('Stock Transfer Items cannot be deleted.'));
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function sourceInventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'source_product_inventory_id');
    }

    public function destinationInventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'destination_product_inventory_id');
    }

    public function inTransitQuantity(): int
    {
        return $this->dispatched_quantity - $this->received_quantity - $this->returned_quantity - $this->lost_quantity;
    }
}
