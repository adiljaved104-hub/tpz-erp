<?php

namespace App\Models;

use App\Enums\StockRequestSourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockRequestSourceLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'proposed_quantity' => 'integer',
            'status' => StockRequestSourceStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StockRequestSourceLine $line): void {
            if ($line->isDirty([
                'stock_request_id',
                'stock_request_item_id',
                'inventory_allocation_account_id',
                'source_type',
                'source_label',
                'proposed_quantity',
            ])) {
                throw new LogicException('Stock Request source routing is immutable.');
            }

            if ($line->getRawOriginal('status') !== StockRequestSourceStatus::Pending->value) {
                throw new LogicException('Stock Request source decisions are immutable.');
            }
        });

        static::deleting(fn (): never => throw new LogicException('Stock Request source lines cannot be deleted.'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(StockRequestItem::class, 'stock_request_item_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'inventory_allocation_account_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    public function requiresOwnerAdminApproval(): bool
    {
        return $this->source_type !== 'employee';
    }
}
