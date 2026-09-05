<?php

namespace App\Models;

use App\Enums\HardwareSubsystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductHardwareSlot extends Model
{
    protected $fillable = ['product_hardware_profile_id', 'subsystem', 'slot_key', 'interface_type', 'is_soldered', 'is_occupied', 'base_component_id', 'base_capacity_value', 'base_capacity_unit', 'position', 'notes'];

    protected function casts(): array
    {
        return ['subsystem' => HardwareSubsystem::class, 'is_soldered' => 'boolean', 'is_occupied' => 'boolean', 'base_capacity_value' => 'decimal:4', 'position' => 'integer'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ProductHardwareProfile::class, 'product_hardware_profile_id');
    }

    public function baseComponent(): BelongsTo
    {
        return $this->belongsTo(Component::class, 'base_component_id');
    }
}
