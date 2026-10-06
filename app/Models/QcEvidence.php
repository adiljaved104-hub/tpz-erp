<?php

namespace App\Models;

use App\Models\Concerns\ProtectsCertifiedQc;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcEvidence extends Model
{
    use ProtectsCertifiedQc;

    protected $table = 'qc_evidence';

    protected $guarded = [];

    protected $hidden = ['original_path', 'customer_path', 'checksum'];

    protected function casts(): array
    {
        return ['customer_visible' => 'boolean', 'uploaded_at' => 'immutable_datetime'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'inspection_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
