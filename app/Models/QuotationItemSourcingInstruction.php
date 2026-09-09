<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QuotationItemSourcingInstruction extends Model
{
    protected $guarded = [];

    protected $hidden = ['purchase_unit_cost', 'source_note'];

    protected function casts(): array
    {
        return ['purchase_unit_cost' => 'decimal:4', 'planned_source_quantity_snapshot' => 'integer'];
    }

    protected static function booted(): void
    {
        $guard = function (self $record): void {
            if (QuotationItem::query()->whereIn('id', array_filter([$record->quotation_item_id, $record->getRawOriginal('quotation_item_id')]))
                ->whereHas('quotation', fn ($q) => $q->where('status', '!=', QuotationStatus::Draft->value))->exists()) {
                throw new LogicException('Sent Quotation sourcing instructions are immutable.');
            }
        };
        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class);
    }
}
