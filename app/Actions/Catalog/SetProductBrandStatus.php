<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\Enums\CatalogPermission;
use App\Models\ProductBrand;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetProductBrandStatus
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(ProductBrand $brand, ChangeCatalogStatusData $data, User $actor): ProductBrand
    {
        $this->authorization->authorize($actor, CatalogPermission::BrandManage, $brand);
        $validated = Validator::make(['active' => $data->active, 'reason' => trim($data->reason)], [
            'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($brand, $validated, $actor): ProductBrand {
            $brand = ProductBrand::query()->lockForUpdate()->findOrFail($brand->id);
            $before = $brand->status;
            if ($before === $validated['active']) {
                return $brand;
            }
            $brand->forceFill(['status' => $validated['active']])->save();
            $this->activity->log('catalog.brand.status_changed', $actor, $brand, ['from_active' => $before, 'to_active' => $brand->status, 'reason' => $validated['reason']]);

            return $brand;
        });
    }
}
