<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcRecordedStockBypassReservationAllocation extends Model
{
    protected $table = 'qc_bypass_release_reservation_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'reserved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            $allowed = ['status', 'released_by_user_id', 'released_at', 'fulfilled_by_user_id', 'fulfilled_at', 'updated_at'];
            if (array_diff(array_keys($allocation->getDirty()), $allowed) !== []) {
                throw new LogicException('QC exception reservation provenance is immutable.');
            }

            $from = (string) $allocation->getOriginal('status');
            $to = (string) $allocation->status;
            if ($from !== 'reserved' || ! in_array($to, ['released', 'fulfilled'], true)) {
                throw new LogicException('QC exception reservation provenance has an invalid state transition.');
            }
        });
        static::deleting(fn () => throw new LogicException('QC exception reservation provenance is immutable.'));
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(QcRecordedStockBypassRelease::class, 'bypass_release_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function allocationAccount(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class);
    }
}
