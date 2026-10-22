<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class LeaveRequestEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Leave request events are immutable.'));
        static::deleting(fn () => throw new LogicException('Leave request events cannot be deleted.'));
    }
}
