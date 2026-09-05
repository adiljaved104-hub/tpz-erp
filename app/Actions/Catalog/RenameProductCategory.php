<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\RenameCatalogItemData;
use App\Enums\CatalogPermission;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\CatalogNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RenameProductCategory
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly CatalogNameNormalizer $names, private readonly ActivityLogger $activity) {}

    public function handle(ProductCategory $category, RenameCatalogItemData $data, User $actor): ProductCategory
    {
        $this->authorization->authorize($actor, CatalogPermission::CategoryManage, $category);
        $name = $this->names->display($data->name);
        $normalized = $this->names->normalize($name);
        Validator::make(['name' => $name, 'normalized_name' => $normalized], [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => ['required', 'string', 'max:255', Rule::unique('product_categories', 'normalized_name')->ignore($category)],
        ], ['normalized_name.unique' => 'A Category with the same logical name already exists.'])->validate();

        return DB::transaction(function () use ($category, $name, $normalized, $actor): ProductCategory {
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $category->name;
            $category->forceFill(['name' => $name, 'normalized_name' => $normalized])->save();
            if ($before !== $category->name) {
                $this->activity->log('catalog.category.renamed', $actor, $category, ['old_name' => $before, 'new_name' => $category->name]);
            }

            return $category;
        });
    }
}
