<?php

namespace App\Models;

use App\Enums\AccountingAccountType;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingAccount extends \Illuminate\Database\Eloquent\Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['account_type' => AccountingAccountType::class, 'active' => 'boolean', 'is_control' => 'boolean', 'is_posting' => 'boolean', 'protected' => 'boolean'];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(AccountingBook::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function journalLines(): HasMany
    {
        return $this->hasMany(AccountingJournalLine::class, 'account_id');
    }
}
