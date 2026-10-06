<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcOrderAssignment extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_item_id' => 'integer', 'qc_device_id' => 'integer', 'qc_certificate_id' => 'integer', 'active_device_id' => 'integer', 'certificate_version' => 'integer', 'assigned_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('QC assignment history cannot be deleted.'));
        static::updating(function (self $assignment): void {
            if ($assignment->getRawOriginal('released_at') !== null
                || array_diff(array_keys($assignment->getDirty()), ['active_device_id', 'released_at', 'released_by_user_id', 'release_reason']) !== []
                || $assignment->active_device_id !== null || $assignment->released_at === null
                || $assignment->released_by_user_id === null || blank($assignment->release_reason)) {
                throw new LogicException('Only a complete, one-time release may change a QC assignment.');
            }
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')->whereNotNull('active_device_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
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
