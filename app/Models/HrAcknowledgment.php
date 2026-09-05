<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HrAcknowledgment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['read_at' => 'immutable_datetime', 'acknowledged_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (HrAcknowledgment $acknowledgment): void {
            if ($acknowledgment->getOriginal('acknowledged_at') !== null) {
                throw new LogicException('Acknowledgment history is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Acknowledgment history cannot be deleted.'));
    }

    public function warning(): BelongsTo
    {
        return $this->belongsTo(EmployeeWarning::class, 'employee_warning_id');
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(HrNotice::class, 'hr_notice_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
