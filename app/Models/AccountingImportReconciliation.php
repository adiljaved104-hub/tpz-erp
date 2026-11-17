<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingImportReconciliation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'source_record_count' => 'integer',
            'source_debits' => 'decimal:2',
            'source_credits' => 'decimal:2',
            'target_debits' => 'decimal:2',
            'target_credits' => 'decimal:2',
            'difference_summary' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AccountingImportBatch::class, 'batch_id');
    }
}
