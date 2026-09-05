<?php

namespace App\Models;

use App\Enums\InventoryReservationKind;
use App\Enums\InventoryReservationStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Database\Factories\InventoryReservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

class InventoryReservation extends Model
{
    /** @use HasFactory<InventoryReservationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'status' => InventoryReservationStatus::class,
            'reservation_kind' => InventoryReservationKind::class,
            'reserved_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $reservation): void {
            $reservation->reservation_kind ??= InventoryReservationKind::BaseProduct;
            $reservation->reservation_key ??= $reservation->order_item_id === null
                ? 'inventory-reservation:'.Str::uuid()
                : "order-item:{$reservation->order_item_id}:base";
        });
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Inventory Reservations cannot be deleted.'));
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function upgradeSelection(): BelongsTo
    {
        return $this->belongsTo(OrderItemUpgradeSelection::class, 'order_item_upgrade_selection_id');
    }

    public function upgradeRecipeLine(): BelongsTo
    {
        return $this->belongsTo(UpgradeRecipeLine::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fulfilledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'fulfilled_by_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'source');
    }
}
