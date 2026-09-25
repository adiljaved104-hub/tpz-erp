<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class StockRequestExecution extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['executed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Stock Request executions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Stock Request executions cannot be deleted.'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function executedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by_user_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(StockRequestExecutionLine::class);
    }
}
