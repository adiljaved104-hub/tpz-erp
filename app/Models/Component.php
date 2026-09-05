<?php

namespace App\Models;

use App\Enums\ComponentType;
use Database\Factories\ComponentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Component extends Model
{
    /** @use HasFactory<ComponentFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id', 'component_type', 'specification', 'capacity_value', 'capacity_unit',
        'interface_type', 'attributes', 'approved_oem_recovery_value',
        'recovery_approved_by_user_id', 'recovery_approved_at', 'recovery_reason',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'component_type' => ComponentType::class,
            'capacity_value' => 'decimal:4',
            'attributes' => 'array',
            'approved_oem_recovery_value' => 'decimal:4',
            'recovery_approved_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(ProductInventory::class, 'product_id', 'product_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id', 'product_id');
    }

    public function recoveryApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recovery_approved_by_user_id');
    }

    public function recoveryValueEvents(): HasMany
    {
        return $this->hasMany(ComponentRecoveryValueEvent::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
