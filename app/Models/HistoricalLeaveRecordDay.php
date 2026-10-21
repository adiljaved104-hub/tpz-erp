<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalLeaveRecordDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_date' => 'immutable_date', 'counts_as_leave' => 'boolean', 'leave_units' => 'decimal:2'];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(HistoricalLeaveRecord::class, 'historical_leave_record_id');
    }
}
