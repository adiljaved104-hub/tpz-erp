<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class BiometricAttendanceEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['punched_at' => 'immutable_datetime', 'imported_at' => 'immutable_datetime', 'raw_metadata' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Imported biometric events are immutable.'));
        static::deleting(fn () => throw new LogicException('Imported biometric events cannot be deleted.'));
    }

    public function employeeMappings(): HasMany
    {
        return $this->hasMany(BiometricEventEmployeeMapping::class);
    }
}
