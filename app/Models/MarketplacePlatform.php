<?php

namespace App\Models;

use App\Enums\MarketplaceReturnHandlingMode;
use Database\Factories\MarketplacePlatformFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketplacePlatform extends Model
{
    /** @use HasFactory<MarketplacePlatformFactory> */
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'status', 'created_by_user_id', 'return_handling_mode', 'default_return_receiving_warehouse_id', 'customer_return_claims_enabled', 'claim_program_name'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'return_handling_mode' => MarketplaceReturnHandlingMode::class,
            'customer_return_claims_enabled' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function assignmentScopes(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignmentPlatform::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function defaultReturnReceivingWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'default_return_receiving_warehouse_id');
    }

    public function inventoryLocations(): HasMany
    {
        return $this->hasMany(Warehouse::class);
    }
}
