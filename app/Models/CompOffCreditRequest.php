<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompOffCreditRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_date' => 'immutable_date', 'to_date' => 'immutable_date',
            'credits_required' => 'integer', 'submitted_at' => 'immutable_datetime',
            'decided_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function days(): HasMany
    {
        return $this->hasMany(CompOffCreditRequestDay::class);
    }
}
