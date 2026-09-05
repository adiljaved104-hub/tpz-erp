<?php

namespace App\Models;

use App\Enums\WarrantyRepairStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarrantyRepairStatusEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_status' => WarrantyRepairStatus::class, 'to_status' => WarrantyRepairStatus::class, 'changed_at' => 'immutable_datetime'];
    }

    public function warrantyRepair(): BelongsTo
    {
        return $this->belongsTo(WarrantyRepair::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
