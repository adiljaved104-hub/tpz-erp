<?php

namespace App\Models;

use Database\Factories\ProductInventoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductInventory extends Model
{
    /** @use HasFactory<ProductInventoryFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['average_cost', 'marketplace_non_sellable_value', 'qc_pending_value'];

    protected $attributes = [
        'marketplace_non_sellable_quantity' => 0,
        'marketplace_non_sellable_value' => '0.0000',
        'qc_pending_quantity' => 0,
        'qc_pending_value' => '0.0000',
    ];

    protected function casts(): array
    {
        return [
            'available_quantity' => 'integer',
            'reserved_quantity' => 'integer',
            'damaged_quantity' => 'integer',
            'average_cost' => 'decimal:4',
            'marketplace_non_sellable_quantity' => 'integer',
            'marketplace_non_sellable_value' => 'decimal:4',
            'qc_pending_quantity' => 'integer',
            'qc_pending_value' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function responsibilityQuantities(): HasMany
    {
        return $this->hasMany(InventoryResponsibilityQuantity::class);
    }

    public function damagedStockEvents(): HasMany
    {
        return $this->hasMany(DamagedStockEvent::class);
    }

    public function sellableQuantity(): int
    {
        return $this->available_quantity - $this->reserved_quantity;
    }

    public function totalOnHand(): int
    {
        return $this->available_quantity + $this->damaged_quantity;
    }

    public function locationTotalOwned(): int
    {
        return $this->totalOnHand()
            + $this->marketplace_non_sellable_quantity
            + $this->qc_pending_quantity;
    }
}
