<?php

namespace App\Models;

use App\Enums\ManualSourcingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebSalesManualSourcingLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => ManualSourcingStatus::class,
            'selling_price_snapshot' => 'decimal:2',
            'materialized_at' => 'immutable_datetime',
            'receipt_confirmed_at' => 'immutable_datetime',
            'fulfilled_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class); }
    public function orderItem(): BelongsTo { return $this->belongsTo(OrderItem::class); }
    public function product(): BelongsTo { return $this->belongsTo(Product::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Supplier::class); }
    public function purchase(): BelongsTo { return $this->belongsTo(Purchase::class); }
    public function purchaseReceipt(): BelongsTo { return $this->belongsTo(PurchaseReceipt::class); }
    public function receiptEvents(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(WebSalesManualSourcingReceiptEvent::class, 'line_id'); }
    public function supplierDirectDeliveries(): \Illuminate\Database\Eloquent\Relations\HasMany { return $this->hasMany(WebSalesSupplierDirectDelivery::class, 'line_id'); }
}
