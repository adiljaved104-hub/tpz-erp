<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompensatoryOff extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'earned_work_date' => 'immutable_date', 'off_date' => 'immutable_date',
            'approved_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
