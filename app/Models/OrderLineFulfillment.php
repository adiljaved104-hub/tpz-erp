<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderLineFulfillment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:4'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    public function events(): HasMany { return $this->hasMany(OrderLineFulfillmentEvent::class, 'fulfillment_id'); }
}
