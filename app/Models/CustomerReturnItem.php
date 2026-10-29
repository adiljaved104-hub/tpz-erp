<?php

namespace App\Models;

use App\Enums\CustomerReturnInspectionResult;
use App\Enums\CustomerReturnReason;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReturnItem extends Model
{
    protected $guarded = [];

    protected $hidden = ['inventory_unit_cost'];

    protected function casts(): array
    {
        return ['fulfilled_quantity_snapshot' => 'integer', 'return_quantity' => 'integer',
            'inventory_unit_cost' => 'decimal:4', 'return_reason' => CustomerReturnReason::class];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Customer Return Items are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Customer Return Items are immutable.'));
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillmentItem::class, 'order_fulfillment_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(CustomerReturnInspection::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(CustomerReturnFinding::class)->orderBy('id');
    }

    public function quarantine(): HasOne
    {
        return $this->hasOne(CustomerReturnQuarantine::class);
    }

    public function exceptionCases(): HasMany
    {
        return $this->hasMany(CustomerReturnExceptionCase::class);
    }

    public function marketplaceDispositions(): HasMany
    {
        return $this->hasMany(CustomerReturnMarketplaceDisposition::class);
    }

    public function removalItems(): HasMany
    {
        return $this->hasMany(MarketplaceReturnRemovalItem::class);
    }

    public function claim(): HasOne
    {
        return $this->hasOne(SafetClaim::class);
    }

    public function companyReceivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'company_receiving_warehouse_id');
    }

    public function inspectedQuantity(): int
    {
        return (int) CustomerReturnInspection::query()
            ->where('customer_return_item_id', $this->id)
            ->orWhereIn('marketplace_return_removal_item_id', $this->removalItems()->select('id'))
            ->sum('quantity');
    }

    public function hasIdentityDiscrepancy(): bool
    {
        return $this->findings()->whereIn('problem_type', ['wrong_item', 'serial_mismatch', 'empty_package'])->exists();
    }

    public function qcEvidenceReviewReason(): ?string
    {
        $this->loadMissing('inspections', 'findings');
        if ($this->inspections->isEmpty() || $this->findings->isEmpty()) {
            return null;
        }

        $problems = $this->findings->pluck('problem_type')->all();
        if (array_intersect($problems, ['wrong_item', 'serial_mismatch', 'empty_package']) !== []) {
            return 'New evidence questions the identity of a product already posted through QC. Owner/Admin review is needed; inventory has not been changed.';
        }

        $passed = $this->inspections->contains(fn (CustomerReturnInspection $inspection): bool => $inspection->result === CustomerReturnInspectionResult::Sellable);
        $failed = $this->inspections->contains(fn (CustomerReturnInspection $inspection): bool => $inspection->result === CustomerReturnInspectionResult::Damaged);
        if ($passed && ! $failed && array_intersect($problems, ['damaged', 'defective', 'missing_accessories']) !== []) {
            return 'New evidence describes damage after QC PASS. Owner/Admin review is needed; inventory has not been changed.';
        }
        if ($failed && ! $passed && in_array('sellable', $problems, true)) {
            return 'New evidence describes a sellable item after QC FAIL. Owner/Admin review is needed; inventory has not been changed.';
        }

        return null;
    }
}
