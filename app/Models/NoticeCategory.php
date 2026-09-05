<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

class NoticeCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (NoticeCategory $category): void {
            $category->name = str($category->name)->squish()->toString();
            $category->normalized_name = str($category->name)->lower()->toString();

            if (self::query()->where('normalized_name', $category->normalized_name)
                ->when($category->exists, fn ($query) => $query->whereKeyNot($category->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['data.name' => 'A Notice Category with this name already exists.']);
            }
        });
        static::deleting(fn () => throw new LogicException('Notice Categories cannot be hard-deleted. Deactivate the Category instead.'));
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(HrNotice::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(NoticeTemplate::class);
    }
}
