<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderSetting extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amendment_window_hours' => 'integer'];
    }
}
