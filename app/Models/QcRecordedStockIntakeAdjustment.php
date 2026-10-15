<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcRecordedStockIntakeAdjustment extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recorded_stock_intake_id' => 'integer',
            'quantity_before' => 'integer',
            'adjustment_quantity' => 'integer',
            'quantity_after' => 'integer',
            'unit_cost' => 'decimal:4',
            'value_reduction' => 'decimal:4',
            'performed_by_user_id' => 'integer',
            'stock_movement_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Recorded QC intake adjustments are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Recorded QC intake adjustments are immutable.'));
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(QcRecordedStockIntake::class, 'recorded_stock_intake_id');
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
