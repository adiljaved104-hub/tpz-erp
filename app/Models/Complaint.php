<?php

namespace App\Models;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Complaint extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'category' => ComplaintCategory::class, 'status' => ComplaintStatus::class, 'resolution' => ComplaintResolution::class, 'opened_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warrantyRepair(): BelongsTo
    {
        return $this->belongsTo(WarrantyRepair::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(ComplaintStatusEvent::class)->orderBy('changed_at');
    }
}
