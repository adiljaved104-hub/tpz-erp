<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HistoricalLeaveImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['preview_summary' => 'array', 'confirmed_at' => 'immutable_datetime'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function records(): HasMany
    {
        return $this->hasMany(HistoricalLeaveRecord::class, 'import_batch_id');
    }
}
