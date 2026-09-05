<?php

namespace App\Models;

use Database\Factories\ProductBrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductBrand extends Model
{
    /** @use HasFactory<ProductBrandFactory> */
    use HasFactory;

    protected $fillable = ['name', 'normalized_name', 'status', 'created_by_user_id'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function responsibilityScopes(): HasMany
    {
        return $this->hasMany(ResponsibilityAssignmentBrand::class);
    }
}
