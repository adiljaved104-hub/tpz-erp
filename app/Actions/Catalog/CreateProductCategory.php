<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\CreateCatalogItemData;
use App\Enums\CatalogPermission;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\CatalogNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateProductCategory
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly CatalogNameNormalizer $names, private readonly ActivityLogger $activity) {}

    public function handle(CreateCatalogItemData $data, User $actor): ProductCategory
    {
        $this->authorization->authorize($actor, CatalogPermission::CategoryManage);
        $name = $this->names->display($data->name);
        $normalized = $this->names->normalize($name);
        Validator::make(['name' => $name, 'normalized_name' => $normalized], [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => ['required', 'string', 'max:255', Rule::unique('product_categories', 'normalized_name')],
        ], ['normalized_name.unique' => 'A Category with the same logical name already exists.'])->validate();

        return DB::transaction(function () use ($name, $normalized, $actor): ProductCategory {
            $category = ProductCategory::query()->create(['name' => $name, 'normalized_name' => $normalized, 'status' => true, 'created_by_user_id' => $actor->id]);
            $this->activity->log('catalog.category.created', $actor, $category, ['name' => $category->name, 'active' => true]);

            return $category;
        });
    }
}
