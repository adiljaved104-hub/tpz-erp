<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebSalesManualSourcingReceiptEvent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['confirmed_at' => 'immutable_datetime'];
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(WebSalesManualSourcingLine::class, 'line_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseReceipt::class, 'purchase_receipt_id');
    }
}
