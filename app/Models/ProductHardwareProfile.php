<?php

namespace App\Models;

use App\Enums\HardwareSubsystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductHardwareProfile extends Model
{
    protected $fillable = ['product_id', 'profile_version', 'ram_upgradeable', 'max_supported_ram_mb', 'storage_upgradeable', 'notes', 'created_by_user_id', 'updated_by_user_id'];

    protected function casts(): array
    {
        return ['profile_version' => 'integer', 'ram_upgradeable' => 'boolean', 'max_supported_ram_mb' => 'integer', 'storage_upgradeable' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function slots(): HasMany
    {
        return $this->hasMany(ProductHardwareSlot::class)->orderBy('position')->orderBy('id');
    }

    public function ramSlots(): HasMany
    {
        return $this->hasMany(ProductHardwareSlot::class)->where('subsystem', 'ram')->orderBy('position')->orderBy('id');
    }

    public function storageSlots(): HasMany
    {
        return $this->hasMany(ProductHardwareSlot::class)->where('subsystem', 'storage')->orderBy('position')->orderBy('id');
    }

    /** @return array{ram_total: int, ram_occupied: int, ram_free: int, storage_total: int, storage_occupied: int, storage_free: int} */
    public function derivedSlotCounts(): array
    {
        $slots = $this->relationLoaded('slots') ? $this->slots : $this->slots()->get();
        $ram = $slots->where('subsystem', HardwareSubsystem::Ram);
        $storage = $slots->where('subsystem', HardwareSubsystem::Storage);

        return [
            'ram_total' => $ram->count(), 'ram_occupied' => $ram->where('is_occupied', true)->count(), 'ram_free' => $ram->where('is_occupied', false)->count(),
            'storage_total' => $storage->count(), 'storage_occupied' => $storage->where('is_occupied', true)->count(), 'storage_free' => $storage->where('is_occupied', false)->count(),
        ];
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
