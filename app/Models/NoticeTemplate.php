<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

class NoticeTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'default_acknowledgment_required' => 'boolean',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (NoticeTemplate $template): void {
            $template->name = str($template->name)->squish()->toString();
            $template->normalized_name = str($template->name)->lower()->toString();
            $template->default_title = trim($template->default_title);
            $template->default_content = trim($template->default_content);

            if (($template->isDirty('notice_category_id') || ! $template->exists)
                && ! NoticeCategory::query()->whereKey($template->notice_category_id)->where('status', true)->exists()) {
                throw ValidationException::withMessages(['data.notice_category_id' => 'Select an active Notice Category.']);
            }
            if (self::query()->where('notice_category_id', $template->notice_category_id)
                ->where('normalized_name', $template->normalized_name)
                ->when($template->exists, fn ($query) => $query->whereKeyNot($template->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['data.name' => 'A Template with this name already exists in the selected Category.']);
            }
        });
        static::deleting(fn () => throw new LogicException('Notice Templates cannot be hard-deleted. Deactivate the Template instead.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(NoticeCategory::class, 'notice_category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function notices(): HasMany
    {
        return $this->hasMany(HrNotice::class);
    }
}
