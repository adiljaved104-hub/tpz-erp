<?php

namespace App\Models;

use App\Enums\OfficeFinanceAccountType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OfficeFinanceAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['account_type' => OfficeFinanceAccountType::class, 'active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(OfficeFinanceTransaction::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
