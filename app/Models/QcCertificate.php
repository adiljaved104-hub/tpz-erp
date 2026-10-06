<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class QcCertificate extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'snapshot' => 'array', 'certified_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('QC certificates are immutable.'));
        static::deleting(fn () => throw new LogicException('QC certificates are immutable.'));
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'inspection_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(QcDevice::class);
    }

    public function isCurrent(): bool
    {
        return ! self::query()->where('device_id', $this->device_id)->where('version', '>', $this->version)->exists() && ! QcInspection::query()->where('active_device_id', $this->device_id)->where('version', '>', $this->version)->exists();
    }
}
