<?php

namespace App\Models;

use App\Enums\WarningLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EmployeeWarning extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'warning_level' => WarningLevel::class,
            'issued_date' => 'immutable_date',
            'acknowledgment_required' => 'boolean',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (EmployeeWarning $warning): void {
            $material = ['employee_id', 'warning_category_id', 'warning_level', 'title', 'description', 'issued_date', 'issued_by_user_id', 'acknowledgment_required'];
            if ($warning->isDirty($material) && $warning->acknowledgments()->whereNotNull('acknowledged_at')->exists()) {
                throw new LogicException('Acknowledged Warning content is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Warning history cannot be hard-deleted.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WarningCategory::class, 'warning_category_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function acknowledgments(): HasMany
    {
        return $this->hasMany(HrAcknowledgment::class);
    }
}
