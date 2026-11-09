<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingBook extends \Illuminate\Database\Eloquent\Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'posting_enabled' => 'boolean'];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(AccountingAccount::class);
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function journals(): HasMany
    {
        return $this->hasMany(AccountingJournal::class);
    }
}
