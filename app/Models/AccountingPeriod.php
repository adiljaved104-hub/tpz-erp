<?php

namespace App\Models;

use App\Enums\AccountingPeriodStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'status' => AccountingPeriodStatus::class, 'closed_at' => 'immutable_datetime'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(AccountingBook::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(AccountingJournal::class, 'period_id');
    }
}
