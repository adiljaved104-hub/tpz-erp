<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricAttendanceSyncRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_from' => 'immutable_datetime',
            'window_to' => 'immutable_datetime',
            'attempted_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }
}
