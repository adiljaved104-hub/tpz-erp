<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use LogicException;

class WarningTemplate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_acknowledgment_required' => 'boolean', 'status' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (WarningTemplate $template): void {
            $template->name = str($template->name)->squish()->toString();
            $template->normalized_name = str($template->name)->lower()->toString();
            $template->default_title = trim($template->default_title);
            $template->default_description = trim($template->default_description);

            if (! WarningCategory::query()->whereKey($template->warning_category_id)->where('status', true)->exists()) {
                throw ValidationException::withMessages(['data.warning_category_id' => 'Select an active Warning Category.']);
            }
            if (self::query()->where('warning_category_id', $template->warning_category_id)
                ->where('normalized_name', $template->normalized_name)
                ->when($template->exists, fn ($query) => $query->whereKeyNot($template->getKey()))
                ->exists()) {
                throw ValidationException::withMessages(['data.name' => 'A Warning Template with this name already exists in the selected Category.']);
            }
        });
        static::deleting(fn () => throw new LogicException('Warning Templates cannot be hard-deleted. Deactivate the Template instead.'));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(WarningCategory::class, 'warning_category_id');
    }

    public function warnings(): HasMany
    {
        return $this->hasMany(EmployeeWarning::class, 'warning_template_id');
    }
}
