<?php

namespace App\Models;

use App\Enums\ProductCondition;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['assignment_id', 'product_condition'])]
class ResponsibilityAssignmentCondition extends Model
{
    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['product_condition' => ProductCondition::class];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class, 'assignment_id');
    }
}
