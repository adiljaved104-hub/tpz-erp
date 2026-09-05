<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRequestDay extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['leave_date' => 'immutable_date', 'counts_as_leave' => 'boolean', 'leave_units' => 'decimal:2'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class, 'leave_request_id');
    }
}
