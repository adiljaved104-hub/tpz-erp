<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\WebSalesChannel;
use App\Enums\WebSalesDeliveryType;
use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'web_sales_channel' => WebSalesChannel::class,
            'delivery_type' => WebSalesDeliveryType::class,
            'status' => OrderStatus::class,
            'order_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'reserved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableOrderException('Orders cannot be deleted.'));
        static::updating(function (Order $order): void {
            if (! $order->isDirty('warehouse_id')) {
                return;
            }

            $wasDraft = $order->getRawOriginal('status') === OrderStatus::Draft->value;
            $hasReservation = $order->items()->whereHas('reservation')->exists();

            if (! $wasDraft || $hasReservation) {
                throw new ImmutableOrderException('Fulfilled From cannot be changed after inventory has been reserved.');
            }
        });
    }

    public function scopeOperational(Builder $query): Builder
    {
        return $query->whereNotIn('status', [OrderStatus::Cancelled->value]);
    }

    public function scopeWebSales(Builder $query): Builder
    {
        return $query->whereNotNull('web_sales_channel');
    }

    public function webSalesStatusLabel(): string
    {
        if ($this->status === OrderStatus::Cancelled) {
            return 'Cancelled';
        }

        if ($this->delivered_at !== null) {
            return 'Delivered';
        }

        return match ($this->status) {
            OrderStatus::Draft => 'New',
            OrderStatus::Confirmed, OrderStatus::Reserved, OrderStatus::Processing, OrderStatus::PendingReview => 'Confirmed',
            OrderStatus::Fulfilled => 'Shipped',
            OrderStatus::Cancelled => 'Cancelled',
        };
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(MarketplacePlatform::class, 'marketplace_platform_id');
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handled_by_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reservedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reserved_by_user_id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('line_number')->orderBy('id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function fulfillment(): HasOne
    {
        return $this->hasOne(OrderFulfillment::class);
    }

    public function customerReturns(): HasMany
    {
        return $this->hasMany(CustomerReturn::class);
    }

    public function warrantyRepairs(): HasMany
    {
        return $this->hasMany(WarrantyRepair::class);
    }
}
