<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePolicy extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'absence_equivalent_penalty_days' => 'decimal:2',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'status' => 'boolean',
        ];
    }
}
