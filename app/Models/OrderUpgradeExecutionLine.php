<?php

namespace App\Models;

use App\Enums\UpgradeRecipeOperation;
use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderUpgradeExecutionLine extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'operation' => UpgradeRecipeOperation::class,
            'slot_snapshot' => 'array',
            'component_specification_snapshot' => 'array',
            'quantity' => 'integer',
            'actual_unit_cost' => 'decimal:4',
            'total_value' => 'decimal:4',
            'recovery_approved_unit_value_snapshot' => 'decimal:4',
            'recovery_applied_unit_value_snapshot' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order upgrade execution lines are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order upgrade execution lines are immutable.'));
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(OrderUpgradeExecution::class, 'order_upgrade_execution_id');
    }

    public function recipeLine(): BelongsTo
    {
        return $this->belongsTo(UpgradeRecipeLine::class, 'upgrade_recipe_line_id');
    }

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function movement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
