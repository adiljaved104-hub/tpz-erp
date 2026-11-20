<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingImportSourceIdentity extends Model
{
    protected $guarded = [];

    public function batchItems(): HasMany
    {
        return $this->hasMany(AccountingImportBatchItem::class, 'source_identity_id');
    }
}
