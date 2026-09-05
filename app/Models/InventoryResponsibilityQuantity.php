<?php

namespace App\Models;

use App\Exceptions\ImmutableResponsibilityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryResponsibilityQuantity extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['assigned_quantity' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableResponsibilityException('Responsibility quantities cannot be changed.'));
        static::deleting(fn (): never => throw new ImmutableResponsibilityException('Responsibility quantities cannot be deleted.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }
}
