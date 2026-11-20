<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingImportBatchItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_transaction_date' => 'date',
            'source_due_date' => 'date',
            'record_payload' => 'array',
            'validation_errors' => 'array',
            'source_exchange_rate' => 'decimal:8',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }

    public function sourceIdentity(): BelongsTo
    {
        return $this->belongsTo(AccountingImportSourceIdentity::class, 'source_identity_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(AccountingImportLink::class, 'batch_item_id');
    }
}
