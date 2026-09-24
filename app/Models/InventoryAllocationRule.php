<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAllocationRule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['priority' => 'integer', 'status' => 'boolean'];
    }

    public function targetAccount(): BelongsTo
    {
        return $this->belongsTo(InventoryAllocationAccount::class, 'target_account_id');
    }
}
