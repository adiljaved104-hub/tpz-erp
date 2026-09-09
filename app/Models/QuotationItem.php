<?php

namespace App\Models;

use App\Enums\ProductCondition;
use App\Enums\QuotationItemSourceType;
use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class QuotationItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer', 'unit_price_including_vat' => 'decimal:2',
            'discount_amount' => 'decimal:2', 'vat_rate' => 'decimal:4',
            'subtotal_excluding_vat' => 'decimal:2', 'vat_amount' => 'decimal:2',
            'total_including_vat' => 'decimal:2',
            'source_type' => QuotationItemSourceType::class,
            'manual_condition' => ProductCondition::class,
            'materialized_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        $guard = function (self $item): void {
            $workflowFields = ['materialized_product_id', 'materialized_by_user_id', 'materialized_at', 'updated_at'];
            if ($item->source_type === QuotationItemSourceType::ManualSourced
                && $item->getRawOriginal('materialized_product_id') === null
                && array_diff(array_keys($item->getDirty()), $workflowFields) === []) {
                return;
            }
            if ($item->quotation()->where('status', '!=', QuotationStatus::Draft->value)->exists()) {
                throw new LogicException('Sent Quotation items are immutable.');
            }
        };
        static::updating($guard);
        static::deleting($guard);
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function manualBrand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'manual_brand_id');
    }

    public function manualCategory(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'manual_category_id');
    }

    public function materializedProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'materialized_product_id');
    }

    public function resolvedProductId(): ?int
    {
        return $this->product_id ?? $this->materialized_product_id;
    }

    public function sourcingInstruction(): HasOne
    {
        return $this->hasOne(QuotationItemSourcingInstruction::class);
    }

    public function orderItem(): HasOne
    {
        return $this->hasOne(OrderItem::class);
    }
}
