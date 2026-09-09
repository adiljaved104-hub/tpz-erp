<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class OrderItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ordered_quantity' => 'integer',
            'line_number' => 'integer',
            'selling_price' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'vat_rate' => 'decimal:4',
            'vat_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $item): void {
            $item->line_key ??= (string) Str::uuid();
            $item->line_number ??= ((int) self::query()->where('order_id', $item->order_id)->max('line_number')) + 1;
        });
        static::updating(function (self $item): void {
            if ($item->order()->where('status', '!=', OrderStatus::Draft->value)->exists()) {
                throw new ImmutableOrderException('Reserved Order Items cannot be changed.');
            }
        });
        static::deleting(function (self $item): void {
            if ($item->order()->where('status', '!=', OrderStatus::Draft->value)->exists()) {
                throw new ImmutableOrderException('Reserved Order Items cannot be deleted.');
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(InventoryReservation::class)->where('reservation_kind', 'base_product');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class)->orderBy('id');
    }

    public function componentReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class)->where('reservation_kind', 'upgrade_component')->orderBy('id');
    }

    public function upgradeSelection(): HasOne
    {
        return $this->hasOne(OrderItemUpgradeSelection::class);
    }

    public function fulfillmentItem(): HasOne
    {
        return $this->hasOne(OrderFulfillmentItem::class);
    }

    public function customerDescription(): string
    {
        $configuration = $this->upgradeSelection?->description();

        return $configuration === null ? $this->product_name : $this->product_name."\n".$configuration;
    }
}
