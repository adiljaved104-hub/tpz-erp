<?php

namespace App\Models;

use App\Enums\QualityControlCheckInputType;
use App\Enums\QualityControlOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityControlInspectionResult extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_type_snapshot' => QualityControlCheckInputType::class,
            'required_snapshot' => 'boolean',
            'critical_snapshot' => 'boolean',
            'allow_na_snapshot' => 'boolean',
            'outcome' => QualityControlOutcome::class,
            'numeric_value' => 'decimal:4',
            'evidence_required' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QualityControlInspection::class, 'quality_control_inspection_id');
    }
}
