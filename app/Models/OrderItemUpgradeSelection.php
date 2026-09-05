<?php

namespace App\Models;

use App\Exceptions\ImmutableOrderException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderItemUpgradeSelection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'hardware_profile_version' => 'integer',
            'configuration_snapshot' => 'array',
            'recipe_snapshot' => 'array',
            'suggested_selling_addon_snapshot' => 'decimal:2',
            'labour_cost_snapshot' => 'decimal:4',
            'recovery_snapshot' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableOrderException('Order upgrade selections are immutable.'));
        static::deleting(fn (): never => throw new ImmutableOrderException('Order upgrade selections are immutable.'));
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function salesConfiguration(): BelongsTo
    {
        return $this->belongsTo(SalesConfiguration::class);
    }

    public function upgradeRecipe(): BelongsTo
    {
        return $this->belongsTo(UpgradeRecipe::class);
    }

    public function selectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'selected_by_user_id');
    }

    public function componentReservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function execution(): HasOne
    {
        return $this->hasOne(OrderUpgradeExecution::class);
    }

    public function description(): string
    {
        return (string) ($this->configuration_snapshot['display_name'] ?? 'Upgraded Configuration');
    }
}
