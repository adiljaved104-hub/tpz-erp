<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'consumes_annual_entitlement' => 'boolean',
            'is_paid' => 'boolean',
            'status' => 'boolean',
        ];
    }
}
