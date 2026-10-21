<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalLeaveRecord extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date', 'leave_period_start' => 'immutable_date', 'leave_period_end' => 'immutable_date', 'chargeable_days' => 'decimal:2', 'original_approved_at' => 'immutable_date', 'recorded_at' => 'immutable_datetime', 'approved_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(HistoricalLeaveImportBatch::class, 'import_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function days(): HasMany
    {
        return $this->hasMany(HistoricalLeaveRecordDay::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(HistoricalLeaveEvent::class);
    }
}
