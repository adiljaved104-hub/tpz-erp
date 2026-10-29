<?php

namespace App\Models;

use App\Enums\InventoryReservationStatus;
use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerReturnExceptionCase extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['affected_quantity' => 'integer', 'resolved_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return exception history cannot be deleted.'));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnItem::class, 'customer_return_item_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnFinding::class);
    }

    public function evidence(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnEvidence::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'inventory_reservation_id');
    }

    public function damagedStockEvent(): BelongsTo
    {
        return $this->belongsTo(DamagedStockEvent::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(CustomerReturnExceptionEvent::class, 'case_id')->orderBy('id');
    }

    public function hasVerifiedInventoryHold(): bool
    {
        if ($this->protection_status !== 'held' || $this->inventory_reservation_id === null) {
            return false;
        }

        $reservation = $this->reservation()->with('inventory')->first();
        if ($reservation === null || $reservation->status !== InventoryReservationStatus::Active
            || $reservation->idempotency_key !== $this->hold_key
            || $reservation->quantity !== $this->affected_quantity
            || $reservation->inventory === null
            || $reservation->inventory->reserved_quantity < $reservation->quantity) {
            return false;
        }

        return (int) InventoryAllocationReservationLine::query()
            ->where('inventory_reservation_id', $reservation->id)->where('status', 'reserved')
            ->sum('quantity') === $reservation->quantity;
    }
}
