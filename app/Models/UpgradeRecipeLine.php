<?php

namespace App\Models;

use App\Enums\RecoveryValuationMethod;
use App\Enums\UpgradeRecipeOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UpgradeRecipeLine extends Model
{
    protected $fillable = ['upgrade_recipe_id', 'sequence', 'operation', 'source_slot_key', 'target_slot_key', 'install_component_id', 'recovered_component_id', 'quantity_per_laptop', 'recovery_valuation_method', 'recovery_value_override', 'override_reason', 'recovery_approved_by_user_id', 'recovery_approved_at'];

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'operation' => UpgradeRecipeOperation::class, 'quantity_per_laptop' => 'decimal:4', 'recovery_valuation_method' => RecoveryValuationMethod::class, 'recovery_value_override' => 'decimal:4', 'recovery_approved_at' => 'immutable_datetime'];
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(UpgradeRecipe::class, 'upgrade_recipe_id');
    }

    public function installComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'install_component_id');
    }

    public function recoveredComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'recovered_component_id');
    }

    public function recoveryApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_approved_by_user_id');
    }
}
