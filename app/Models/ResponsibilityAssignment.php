<?php

namespace App\Models;

use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Exceptions\ImmutableResponsibilityException;
use Database\Factories\ResponsibilityAssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ResponsibilityAssignment extends Model
{
    /** @use HasFactory<ResponsibilityAssignmentFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'assignment_mode' => ResponsibilityAssignmentMode::class,
            'status' => ResponsibilityAssignmentStatus::class,
            'effective_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ImmutableResponsibilityException('Responsibility Assignments cannot be deleted.'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ResponsibilityAssignmentStatus::Active->value)->whereNull('ended_at');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function teamAtAssignment(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'team_id_at_assignment');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_assignment_id');
    }

    public function successors(): HasMany
    {
        return $this->hasMany(self::class, 'predecessor_assignment_id');
    }

    public function brandScope(): HasOne
    {
        return $this->hasOne(ResponsibilityAssignmentBrand::class, 'assignment_id');
    }

    public function platformScope(): HasOne
    {
        return $this->hasOne(ResponsibilityAssignmentPlatform::class, 'assignment_id');
    }

    public function productScope(): HasOne
    {
        return $this->hasOne(ResponsibilityAssignmentProduct::class, 'assignment_id');
    }

    public function quantityScope(): HasOne
    {
        return $this->hasOne(InventoryResponsibilityQuantity::class, 'assignment_id');
    }
}
