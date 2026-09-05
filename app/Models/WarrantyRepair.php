<?php

namespace App\Models;

use App\Enums\WarrantyInspectionResult;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class WarrantyRepair extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'source' => WarrantyRepairSource::class, 'status' => WarrantyRepairStatus::class, 'inspection_result' => WarrantyInspectionResult::class, 'received_at' => 'immutable_datetime', 'sent_to_technician_at' => 'immutable_datetime', 'expected_return_at' => 'immutable_datetime', 'repair_completed_at' => 'immutable_datetime', 'received_back_at' => 'immutable_datetime', 'qc_at' => 'immutable_datetime', 'dispatched_back_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'moved_to_damaged_quantity' => 'integer', 'moved_to_damaged_at' => 'immutable_datetime'];
    }

    public function scopeInternalCompanyOwned(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where('source', WarrantyRepairSource::DamagedItem->value)
                ->orWhereNotNull('damaged_stock_event_id');
        });
    }

    public function scopeExternalService(Builder $query): Builder
    {
        return $query->where('source', '!=', WarrantyRepairSource::DamagedItem->value)
            ->whereNull('damaged_stock_event_id');
    }

    public function isInternalCompanyOwnedRepair(): bool
    {
        return $this->source === WarrantyRepairSource::DamagedItem || $this->damaged_stock_event_id !== null;
    }

    public function isLegacyDamagedRepair(): bool
    {
        return $this->source === WarrantyRepairSource::DamagedItem
            && $this->damaged_stock_event_id === null
            && $this->product_inventory_id !== null;
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

    public function damagedStockEvent(): BelongsTo
    {
        return $this->belongsTo(DamagedStockEvent::class);
    }

    public function movedToDamagedEvent(): BelongsTo
    {
        return $this->belongsTo(DamagedStockEvent::class, 'moved_to_damaged_event_id');
    }

    public function movedToDamagedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moved_to_damaged_by_user_id');
    }

    public function complaint(): HasOne
    {
        return $this->hasOne(Complaint::class);
    }

    public function refund(): HasOne
    {
        return $this->hasOne(CustomerReturnRefund::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(WarrantyRepairStatusEvent::class)->orderBy('changed_at');
    }

    public function lastStatusEvent(): HasOne
    {
        return $this->hasOne(WarrantyRepairStatusEvent::class)->latestOfMany('changed_at');
    }

    public function sentToTechnicianEvent(): HasOne
    {
        return $this->hasOne(WarrantyRepairStatusEvent::class)
            ->where('to_status', WarrantyRepairStatus::SendToTechnician->value)
            ->oldestOfMany('changed_at');
    }
}
