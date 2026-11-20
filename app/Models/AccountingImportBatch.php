<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountingImportBatch extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dry_run' => 'boolean',
            'checkpoint' => 'array',
            'error_summary' => 'array',
            'preview_started_at' => 'immutable_datetime',
            'preview_finished_at' => 'immutable_datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(AccountingImportBatchItem::class, 'batch_id');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(AccountingImportMapping::class, 'batch_id');
    }

    public function reconciliations(): HasMany
    {
        return $this->hasMany(AccountingImportReconciliation::class, 'batch_id');
    }
}
