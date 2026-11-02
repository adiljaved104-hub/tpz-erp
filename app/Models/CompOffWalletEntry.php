<?php

namespace App\Models;

use App\Exceptions\ImmutableInventoryRecordException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompOffWalletEntry extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['earned_delta' => 'integer', 'reserved_delta' => 'integer', 'used_delta' => 'integer',
            'occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new ImmutableInventoryRecordException('Comp-Off wallet entries are immutable.'));
        static::deleting(fn (): never => throw new ImmutableInventoryRecordException('Comp-Off wallet entries cannot be deleted.'));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(CompOffCreditRequest::class, 'comp_off_credit_request_id');
    }

    public function duty(): BelongsTo
    {
        return $this->belongsTo(CompOffSundayDuty::class, 'comp_off_sunday_duty_id');
    }
}
