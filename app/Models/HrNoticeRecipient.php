<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class HrNoticeRecipient extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Published Notice recipients are immutable.'));
        static::deleting(fn () => throw new LogicException('Published Notice recipients cannot be removed.'));
    }

    public function notice(): BelongsTo
    {
        return $this->belongsTo(HrNotice::class, 'hr_notice_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
