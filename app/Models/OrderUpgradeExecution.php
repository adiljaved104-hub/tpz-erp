<?php

namespace App\Models;

use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderUpgradeExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'base_cogs' => 'decimal:4',
            'installed_component_cost' => 'decimal:4',
            'recovery_credit' => 'decimal:4',
            'labour_cost' => 'decimal:4',
            'final_configured_cogs' => 'decimal:4',
            'executed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order upgrade executions are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order upgrade executions are immutable.'));
    }

    public function selection(): BelongsTo
    {
        return $this->belongsTo(OrderItemUpgradeSelection::class, 'order_item_upgrade_selection_id');
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(OrderFulfillmentItem::class, 'order_fulfillment_item_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderUpgradeExecutionLine::class)->orderBy('id');
    }
}
