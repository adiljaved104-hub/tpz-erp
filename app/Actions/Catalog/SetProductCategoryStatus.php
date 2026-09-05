<?php

namespace App\Actions\Catalog;

use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\Enums\CatalogPermission;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CatalogAuthorization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SetProductCategoryStatus
{
    public function __construct(private readonly CatalogAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function handle(ProductCategory $category, ChangeCatalogStatusData $data, User $actor): ProductCategory
    {
        $this->authorization->authorize($actor, CatalogPermission::CategoryManage, $category);
        $validated = Validator::make(['active' => $data->active, 'reason' => trim($data->reason)], [
            'active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000'],
        ])->validate();

        return DB::transaction(function () use ($category, $validated, $actor): ProductCategory {
            $category = ProductCategory::query()->lockForUpdate()->findOrFail($category->id);
            $before = $category->status;
            if ($before === $validated['active']) {
                return $category;
            }
            $category->forceFill(['status' => $validated['active']])->save();
            $this->activity->log('catalog.category.status_changed', $actor, $category, ['from_active' => $before, 'to_active' => $category->status, 'reason' => $validated['reason']]);

            return $category;
        });
    }
}
