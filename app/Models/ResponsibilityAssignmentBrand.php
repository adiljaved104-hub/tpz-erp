<?php

namespace App\Models;

use App\Exceptions\ImmutableResponsibilityException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibilityAssignmentBrand extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableResponsibilityException('Responsibility scopes cannot be changed.'));
        static::deleting(fn (): never => throw new ImmutableResponsibilityException('Responsibility scopes cannot be deleted.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(ProductBrand::class, 'product_brand_id');
    }
}
