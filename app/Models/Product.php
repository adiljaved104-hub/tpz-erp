<?php

namespace App\Models;

use App\Enums\InventoryItemType;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'inventory_item_type',
        'brand',
        'brand_id',
        'category',
        'category_id',
        'model',
        'condition',
        'processor',
        'ram',
        'storage',
        'screen_size',
        'graphics',
        'color',
        'warranty',
        'selling_price',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'condition' => ProductCondition::class,
            'inventory_item_type' => InventoryItemType::class,
            'status' => ProductStatus::class,
            'warranty' => 'integer',
            'cost_price' => 'decimal:4',
            'selling_price' => 'decimal:2',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Active->value);
    }

    public function scopeProducts(Builder $query): Builder
    {
        return $query->where('inventory_item_type', InventoryItemType::Product->value);
    }

    public function scopeComponents(Builder $query): Builder
    {
        return $query->where('inventory_item_type', InventoryItemType::Component->value);
    }

    public function component(): HasOne
    {
        return $this->hasOne(Component::class);
    }

    public function hardwareProfile(): HasOne
    {
        return $this->hasOne(ProductHardwareProfile::class);
    }

    public function salesConfigurations(): HasMany
    {
        return $this->hasMany(SalesConfiguration::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ProductInventory::class);
    }

    public function brandRelation(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'brand_id');
    }

    public function categoryRelation(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    public function displayBrandName(): string
    {
        return $this->brandRelation?->name ?? $this->brand;
    }

    public function displayCategoryName(): string
    {
        return $this->categoryRelation?->name ?? $this->category;
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function openingStockEntries(): HasMany
    {
        return $this->hasMany(OpeningStockEntry::class);
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function purchaseReceiptItems(): HasMany
    {
        return $this->hasMany(PurchaseReceiptItem::class);
    }

    public function responsibilityScopes(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignmentProduct::class);
    }

    public static function warrantyLabel(int $months): string
    {
        return match ($months) {
            0 => 'No warranty',
            12 => '1 year',
            24 => '2 years',
            default => $months.' months',
        };
    }
}
