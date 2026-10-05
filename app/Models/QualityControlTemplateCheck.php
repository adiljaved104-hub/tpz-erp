<?php

namespace App\Models;

use App\Enums\QualityControlCheckInputType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualityControlTemplateCheck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input_type' => QualityControlCheckInputType::class,
            'required' => 'boolean',
            'critical' => 'boolean',
            'allow_na' => 'boolean',
            'evidence_on_fail' => 'boolean',
            'validation_rules' => 'array',
            'applicability_rules' => 'array',
            'sequence' => 'integer',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(QualityControlTemplate::class, 'quality_control_template_id');
    }
}
