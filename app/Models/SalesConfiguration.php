<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesConfiguration extends Model
{
    protected $fillable = ['product_id', 'hardware_profile_version', 'display_name', 'target_ram_mb', 'target_storage_total_gb', 'target_storage_layout', 'suggested_selling_addon', 'default_selling_price', 'active', 'created_by_user_id', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['hardware_profile_version' => 'integer', 'target_ram_mb' => 'integer', 'target_storage_total_gb' => 'decimal:4', 'target_storage_layout' => 'array', 'suggested_selling_addon' => 'decimal:2', 'default_selling_price' => 'decimal:2', 'active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(UpgradeRecipe::class)->orderByDesc('preferred')->orderBy('priority')->orderBy('id');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereHas('product.hardwareProfile', fn (Builder $profile): Builder => $profile->whereColumn('product_hardware_profiles.profile_version', 'sales_configurations.hardware_profile_version'));
    }

    public function isStale(): bool
    {
        return $this->product?->hardwareProfile?->profile_version !== $this->hardware_profile_version;
    }
}
