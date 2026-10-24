<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnQuarantine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'actual_custody_quantity' => 'integer',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return quarantine history cannot be deleted.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnFinding::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'custody_warehouse_id');
    }

    public function currentCustodyQuantity(): ?int
    {
        if ($this->actual_custody_quantity === null) {
            return null;
        }

        return $this->disposition === 'returned_to_sender' ? 0 : $this->actual_custody_quantity;
    }
}
