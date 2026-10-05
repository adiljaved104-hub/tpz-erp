<?php

namespace App\Models;

use App\Enums\QualityControlDeviceType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityControlTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'device_type' => QualityControlDeviceType::class,
            'version' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function checks(): HasMany
    {
        return $this->hasMany(QualityControlTemplateCheck::class)->orderBy('sequence')->orderBy('id');
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QualityControlInspection::class);
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
