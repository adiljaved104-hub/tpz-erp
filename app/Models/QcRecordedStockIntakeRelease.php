<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcRecordedStockIntakeRelease extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['certificate_version' => 'integer', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Recorded QC intake releases are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Recorded QC intake releases are immutable.'));
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(QcRecordedStockIntake::class, 'recorded_stock_intake_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QcDevice::class, 'qc_device_id');
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'qc_inspection_id');
    }

    public function certificate(): BelongsTo
    {
        return $this->belongsTo(QcCertificate::class, 'qc_certificate_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }
}
