<?php

namespace App\Models;

use App\Exceptions\ImmutableResponsibilityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibilityAssignmentCategory extends Model
{
    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableResponsibilityException('Responsibility Category scopes are immutable.'));
        static::deleting(fn (): never => throw new ImmutableResponsibilityException('Responsibility Category scopes are immutable.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class, 'assignment_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }
}
