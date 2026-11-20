<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingImportLink extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'applied_amount' => 'decimal:2',
            'source_reference' => 'array',
        ];
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(AccountingImportBatchItem::class, 'batch_item_id');
    }
}
