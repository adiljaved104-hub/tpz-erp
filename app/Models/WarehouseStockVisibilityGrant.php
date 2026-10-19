<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class WarehouseStockVisibilityGrant extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'view_stock' => 'boolean',
            'view_employee_stock_holders' => 'boolean',
            'adjust_warehouse_stock' => 'boolean',
            'consume_other_employees_stock' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (): never => throw new LogicException('Warehouse stock visibility history cannot be deleted.'));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active')->whereNull('ended_at');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by_user_id');
    }
}
