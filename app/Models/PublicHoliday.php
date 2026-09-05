<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicHoliday extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['holiday_date' => 'immutable_date', 'status' => 'boolean'];
    }
}
