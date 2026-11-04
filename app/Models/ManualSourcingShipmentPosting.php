<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only provenance for one successfully committed manual-sourcing shipment.
 *
 * Rows must be inserted only by the atomic shipment posting service, after
 * the linked purchase, direct-fulfillment GRN and inventory consumption post.
 */
class ManualSourcingShipmentPosting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'posted_at' => 'immutable_datetime',
        ];
    }

    public function sourcingLine(): BelongsTo
    {
        return $this->belongsTo(WebSalesManualSourcingLine::class, 'sourcing_line_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function purchaseReceipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }
}
