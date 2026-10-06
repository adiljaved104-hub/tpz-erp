<?php

namespace App\Models;

use App\Enums\QcInspectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class QcInspection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => QcInspectionStatus::class, 'device_id' => 'integer', 'technician_user_id' => 'integer', 'warehouse_id' => 'integer', 'order_id' => 'integer', 'order_item_id' => 'integer', 'version' => 'integer', 'product_snapshot' => 'array', 'order_snapshot' => 'array', 'original_configuration' => 'array', 'requested_configuration' => 'array', 'final_configuration' => 'array', 'features' => 'array', 'completed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        foreach (['updating', 'deleting'] as $event) {
            static::{$event}(function (self $inspection): void {
                if ($inspection->getRawOriginal('status') === QcInspectionStatus::Completed->value) {
                    throw new LogicException('Completed QC is immutable. Start a reinspection instead.');
                }
            });
        }
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QcDevice::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(QcCheckResult::class, 'inspection_id')->orderBy('sort_order');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(QcEvidence::class, 'inspection_id');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(QcCertificate::class, 'inspection_id');
    }
}
