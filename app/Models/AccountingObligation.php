<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingObligation extends Model
{
    protected $guarded = [];
    protected function casts(): array { return ['unit_cost' => 'decimal:4', 'metadata' => 'array', 'posted_at' => 'immutable_datetime']; }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
