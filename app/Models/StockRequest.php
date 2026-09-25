<?php

namespace App\Models;

use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class StockRequest extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'purpose' => StockRequestPurpose::class,
            'status' => StockRequestStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (StockRequest $request): void {
            if ($request->isDirty('reference')) {
                throw new LogicException('Stock Request references are immutable.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stock Requests cannot be deleted.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockRequestItem::class);
    }

    public function sourceLines(): HasMany
    {
        return $this->hasMany(StockRequestSourceLine::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'requested_by_employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function execution(): HasOne
    {
        return $this->hasOne(StockRequestExecution::class);
    }
}
