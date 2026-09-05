<?php

namespace App\Models;

use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplaintStatusEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => ComplaintStatus::class, 'to_status' => ComplaintStatus::class, 'changed_at' => 'immutable_datetime'];
    }

    public function complaint(): BelongsTo
    {
        return $this->belongsTo(Complaint::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
