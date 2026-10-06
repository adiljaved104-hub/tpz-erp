<?php

namespace App\Models;

use App\Models\Concerns\ProtectsCertifiedQc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcCheckResult extends Model
{
    use ProtectsCertifiedQc;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['definition' => 'array', 'applicable' => 'boolean', 'measurement' => 'decimal:3'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'inspection_id');
    }
}
