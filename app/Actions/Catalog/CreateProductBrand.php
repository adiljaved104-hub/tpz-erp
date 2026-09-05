<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\CreateCatalogItemData;
use App\Enums\CatalogPermission;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\CatalogNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CreateProductBrand
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly CatalogNameNormalizer $names, private readonly ActivityLogger $activity) {}

    public function handle(CreateCatalogItemData $data, User $actor): ProductBrand
    {
        $this->authorization->authorize($actor, CatalogPermission::BrandManage);
        $name = $this->names->display($data->name);
        $normalized = $this->names->normalize($name);
        Validator::make(['name' => $name, 'normalized_name' => $normalized], [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => ['required', 'string', 'max:255', Rule::unique('product_brands', 'normalized_name')],
        ], ['normalized_name.unique' => 'A Brand with the same logical name already exists.'])->validate();

        return DB::transaction(function () use ($name, $normalized, $actor): ProductBrand {
            $brand = ProductBrand::query()->create(['name' => $name, 'normalized_name' => $normalized, 'status' => true, 'created_by_user_id' => $actor->id]);
            $this->activity->log('catalog.brand.created', $actor, $brand, ['name' => $brand->name, 'active' => true]);

            return $brand;
        });
    }
}
