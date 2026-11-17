<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingImportMapping extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['mapped_at' => 'immutable_datetime'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }

    public function mapper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'mapped_by_user_id');
    }
}
