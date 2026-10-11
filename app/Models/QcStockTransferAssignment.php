<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcStockTransferAssignment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stock_transfer_id' => 'integer', 'stock_transfer_item_id' => 'integer', 'qc_device_id' => 'integer', 'qc_certificate_id' => 'integer', 'active_device_id' => 'integer', 'certificate_version' => 'integer', 'assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('QC Stock Transfer assignment history cannot be deleted.'));
        static::updating(function (self $assignment): void {
            if ($assignment->getRawOriginal('released_at') !== null
                || array_diff(array_keys($assignment->getDirty()), ['active_device_id', 'released_at', 'released_by_user_id', 'release_reason']) !== []
                || $assignment->active_device_id !== null || $assignment->released_at === null
                || $assignment->released_by_user_id === null || blank($assignment->release_reason)) {
                throw new LogicException('Only a complete, one-time QC Stock Transfer assignment release may change history.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNotNull('active_device_id');
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(StockTransfer::class, 'stock_transfer_id');
    }

    public function transferItem(): BelongsTo
    {
        return $this->belongsTo(StockTransferItem::class, 'stock_transfer_item_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QcDevice::class, 'qc_device_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(QcCertificate::class, 'qc_certificate_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
