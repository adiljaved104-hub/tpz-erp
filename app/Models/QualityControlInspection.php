<?php

namespace App\Models;

use App\Enums\QualityControlCosmeticGrade;
use App\Enums\QualityControlInspectionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QualityControlInspection extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'status' => QualityControlInspectionStatus::class,
            'cosmetic_grade' => QualityControlCosmeticGrade::class,
            'configuration_snapshot' => 'array',
            'order_requirement_snapshot' => 'array',
            'report_revision' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplate::class, 'quality_control_template_id');
    }

    public function results(): HasMany
    {
        return $this->hasMany(QualityControlInspectionResult::class)->orderBy('sequence')->orderBy('id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }
}
