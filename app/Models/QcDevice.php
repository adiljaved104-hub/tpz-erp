<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcDevice extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Device identity cannot be rewritten.'));
        static::deleting(fn () => throw new \LogicException('Device identity cannot be deleted.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'device_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(QcCertificate::class, 'device_id');
    }

    public function orderAssignments(): HasMany
    {
        return $this->hasMany(QcOrderAssignment::class, 'qc_device_id');
    }
}
