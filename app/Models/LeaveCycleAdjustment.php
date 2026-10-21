<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveCycleAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_period_start' => 'immutable_date', 'leave_period_end' => 'immutable_date', 'days' => 'decimal:2', 'recorded_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
