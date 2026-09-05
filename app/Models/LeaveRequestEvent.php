<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class LeaveRequestEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Leave request events are immutable.'));
        static::deleting(fn () => throw new LogicException('Leave request events cannot be deleted.'));
    }
}
