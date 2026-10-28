<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class CustomerReturnEvidence extends Model
{
    protected $table = 'customer_return_evidence';

    protected $guarded = [];

    protected $hidden = ['original_path', 'sha256'];

    protected function casts(): array
    {
        return ['byte_size' => 'integer', 'duration_seconds' => 'integer', 'uploaded_at' => 'immutable_datetime',
            'captured_at_device' => 'immutable_datetime', 'video_rotation_degrees' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Return evidence is immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Return evidence is immutable.'));
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(CustomerReturnFinding::class, 'finding_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function label(): HasOne
    {
        return $this->hasOne(CustomerReturnEvidenceLabel::class, 'customer_return_evidence_id');
    }

    public function deviceCaptureTime(): ?CarbonImmutable
    {
        $raw = $this->getRawOriginal('captured_at_device');
        if ($raw === null) {
            return null;
        }

        // Mobile reports an ISO UTC instant and the IANA zone separately.
        // The database stores the instant without an offset; only the display
        // and claim derivative should convert it into the reported zone.
        return CarbonImmutable::parse((string) $raw, 'UTC')
            ->setTimezone($this->capture_timezone ?: 'UTC');
    }

    public function readableLabel(): ?CustomerReturnEvidenceLabel
    {
        $label = $this->label;
        if ($label === null) {
            return null;
        }

        $path = Storage::disk('local')->path($label->path);

        return is_file($path) && is_readable($path) && filesize($path) > 0 ? $label : null;
    }
}
