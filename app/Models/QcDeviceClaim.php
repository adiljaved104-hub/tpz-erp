<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcDeviceClaim extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['qc_device_id' => 'integer', 'active_device_id' => 'integer', 'claimed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('QC device claim history cannot be deleted.'));
        static::updating(function (self $claim): void {
            if ($claim->getRawOriginal('released_at') !== null
                || array_diff(array_keys($claim->getDirty()), ['active_device_id', 'released_at', 'released_by_user_id', 'release_reason']) !== []
                || $claim->active_device_id !== null || $claim->released_at === null
                || $claim->released_by_user_id === null || blank($claim->release_reason)) {
                throw new LogicException('Only a complete, one-time QC device claim release may change a claim.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotNull('active_device_id')->whereNull('released_at');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QcDevice::class, 'qc_device_id');
    }

    public function orderAssignment(): BelongsTo
    {
        return $this->belongsTo(QcOrderAssignment::class, 'qc_order_assignment_id');
    }

    public function stockTransferAssignment(): BelongsTo
    {
        return $this->belongsTo(QcStockTransferAssignment::class, 'qc_stock_transfer_assignment_id');
    }
}
