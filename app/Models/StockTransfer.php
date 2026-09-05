<?php

namespace App\Models;

use App\Enums\StockTransferStatus;
use App\Exceptions\InvalidStockTransferTransitionException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => StockTransferStatus::class, 'transfer_date' => 'date', 'dispatched_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'returned_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new InvalidStockTransferTransitionException('Stock Transfers cannot be deleted. Cancel a Draft instead.'));
    }

    public function sourceWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handled_by_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function dispatchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatched_by_user_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function displayItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->select(['id', 'stock_transfer_id', 'product_id', 'product_name', 'sku', 'quantity', 'dispatched_quantity', 'received_quantity', 'returned_quantity', 'lost_quantity']);
    }

    public function costedDisplayItems(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->select(['id', 'stock_transfer_id', 'product_id', 'product_name', 'sku', 'quantity', 'dispatched_quantity', 'received_quantity', 'returned_quantity', 'lost_quantity', 'dispatch_unit_cost']);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(StockTransferStatusEvent::class)->orderBy('created_at')->orderBy('id');
    }
}
