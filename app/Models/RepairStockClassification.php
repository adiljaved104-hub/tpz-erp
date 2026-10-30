<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RepairStockClassification extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'quantity' => 'integer',
            'unit_cost' => 'decimal:4',
            'approved_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Repair classifications cannot be updated.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Repair classifications cannot be deleted.'));
    }

    public function repair(): BelongsTo
    {
        return $this->belongsTo(WarrantyRepair::class, 'warranty_repair_id');
    }

    public function destinationProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'destination_product_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'source_movement_id');
    }

    public function destinationMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'destination_movement_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
