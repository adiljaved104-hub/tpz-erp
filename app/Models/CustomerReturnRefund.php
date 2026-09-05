<?php

namespace App\Models;

use App\Enums\ReturnRefundStatus;
use App\Enums\ReturnRefundType;
use App\Exceptions\ReturnRefundException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReturnRefund extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'return_type' => ReturnRefundType::class,
            'status' => ReturnRefundStatus::class,
            'refund_amount' => 'decimal:2',
            'refund_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new ReturnRefundException('Refund records cannot be deleted.'));
    }

    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    public function warrantyRepair(): BelongsTo
    {
        return $this->belongsTo(WarrantyRepair::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
