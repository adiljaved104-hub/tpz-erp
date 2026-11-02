<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcRecordedStockBypassRelease extends Model
{
    protected $table = 'qc_recorded_stock_bypass_releases';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'qc_quantity_before' => 'integer',
            'qc_quantity_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'released_value' => 'decimal:4',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('QC exception releases are immutable.'));
        static::deleting(fn () => throw new LogicException('QC exception releases are immutable.'));
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(QcRecordedStockIntake::class, 'recorded_stock_intake_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
