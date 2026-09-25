<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockRequestItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'proposed_sources' => 'array',
            'system_unassigned_quantity' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function getProposedSourceSummaryAttribute(): string
    {
        return collect($this->proposed_sources)
            ->map(fn (array $source): string => "{$source['label']}: {$source['available_quantity']}")
            ->join(' · ');
    }
}
