<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibilityAssignmentWarehouse extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'assignment_id';

    public $incrementing = false;

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ResponsibilityAssignment::class, 'assignment_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
