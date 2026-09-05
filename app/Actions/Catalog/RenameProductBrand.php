<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\RenameCatalogItemData;
use App\Enums\CatalogPermission;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\CatalogNameNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RenameProductBrand
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly CatalogNameNormalizer $names, private readonly ActivityLogger $activity) {}

    public function handle(ProductBrand $brand, RenameCatalogItemData $data, User $actor): ProductBrand
    {
        $this->authorization->authorize($actor, CatalogPermission::BrandManage, $brand);
        $name = $this->names->display($data->name);
        $normalized = $this->names->normalize($name);
        Validator::make(['name' => $name, 'normalized_name' => $normalized], [
            'name' => ['required', 'string', 'max:255'],
            'normalized_name' => ['required', 'string', 'max:255', Rule::unique('product_brands', 'normalized_name')->ignore($brand)],
        ], ['normalized_name.unique' => 'A Brand with the same logical name already exists.'])->validate();

        return DB::transaction(function () use ($brand, $name, $normalized, $actor): ProductBrand {
            $brand = ProductBrand::query()->lockForUpdate()->findOrFail($brand->id);
            $before = $brand->name;
            $brand->forceFill(['name' => $name, 'normalized_name' => $normalized])->save();
            if ($before !== $brand->name) {
                $this->activity->log('catalog.brand.renamed', $actor, $brand, ['old_name' => $before, 'new_name' => $brand->name]);
            }

            return $brand;
        });
    }
}
