<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function sourceLines(): HasMany
    {
        return $this->hasMany(StockRequestSourceLine::class, 'stock_request_item_id');
    }

    public function getProposedSourceSummaryAttribute(): string
    {
        return collect($this->proposed_sources)
            ->map(fn (array $source): string => "{$source['label']}: {$source['available_quantity']}")
            ->join(' · ');
    }

    public function getSourceApprovalSummaryAttribute(): string
    {
        return $this->sourceLines
            ->map(function (StockRequestSourceLine $line): string {
                $approval = $line->requiresOwnerAdminApproval() && $line->status->value === 'pending'
                    ? 'Owner/Admin Approval Required'
                    : $line->status->getLabel();

                return "{$line->source_label} — {$line->proposed_quantity} — {$approval}";
            })
            ->join("\n");
    }
}
