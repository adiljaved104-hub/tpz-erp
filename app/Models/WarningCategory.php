<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class WarningCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (WarningCategory $category): void {
            $category->name = str($category->name)->squish()->toString();
            $category->normalized_name = str($category->name)->lower()->toString();
        });
        static::deleting(fn () => throw new LogicException('Warning Categories cannot be hard-deleted. Deactivate the Category instead.'));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(EmployeeWarning::class);
    }
}
