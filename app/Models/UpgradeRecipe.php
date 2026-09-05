<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UpgradeRecipe extends Model
{
    protected $fillable = ['sales_configuration_id', 'hardware_profile_version', 'name', 'preferred', 'priority', 'labour_unit_cost', 'active', 'created_by_user_id', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['hardware_profile_version' => 'integer', 'preferred' => 'boolean', 'priority' => 'integer', 'labour_unit_cost' => 'decimal:4', 'active' => 'boolean'];
    }

    public function salesConfiguration(): BelongsTo
    {
        return $this->belongsTo(SalesConfiguration::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(UpgradeRecipeLine::class)->orderBy('sequence')->orderBy('id');
    }
}
