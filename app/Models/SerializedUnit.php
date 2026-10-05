<?php

namespace App\Models;

use App\Enums\QualityControlDeviceType;
use App\Enums\SerializedUnitStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SerializedUnit extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'device_type' => QualityControlDeviceType::class,
            'status' => SerializedUnitStatus::class,
            'initial_configuration' => 'array',
            'current_configuration' => 'array',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(ProductInventory::class, 'product_inventory_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    public function configurationEvents(): HasMany
    {
        return $this->hasMany(SerializedUnitConfigurationEvent::class)->orderBy('occurred_at')->orderBy('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QualityControlInspection::class)->orderByDesc('started_at')->orderByDesc('id');
    }
}
