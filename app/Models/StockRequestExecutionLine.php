<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockRequestExecutionLine extends Model
{
    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Stock Request execution lines are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Stock Request execution lines cannot be deleted.'));
    }
}
