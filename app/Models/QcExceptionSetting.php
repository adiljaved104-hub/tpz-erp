<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcExceptionSetting extends Model
{
    protected $fillable = ['enabled', 'duration_mode', 'expires_at', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'expires_at' => 'immutable_datetime'];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
