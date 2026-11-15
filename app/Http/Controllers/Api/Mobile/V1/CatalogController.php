<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Catalog\CreateProductBrand;
use App\Actions\Catalog\CreateProductCategory;
use App\Actions\Catalog\RenameProductBrand;
use App\Actions\Catalog\RenameProductCategory;
use App\Actions\Catalog\SetProductBrandStatus;
use App\Actions\Catalog\SetProductCategoryStatus;
use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\DTOs\Catalog\RenameCatalogItemData;
use App\Enums\CatalogPermission;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Services\Authorization\CatalogAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends MobileController
{
    public function index(Request $request, string $type): JsonResponse
    {
        $permission = $type === 'brands' ? CatalogPermission::BrandView : CatalogPermission::CategoryView;
        app(CatalogAuthorization::class)->authorize($request->user(), $permission);
        $model = $type === 'brands' ? ProductBrand::class : ProductCategory::class;
        $records = $model::query()->orderBy('name')->get(['id', 'name', 'status']);
        $manage = app(CatalogAuthorization::class)->allows($request->user(), $type === 'brands' ? CatalogPermission::BrandManage : CatalogPermission::CategoryManage);

        return response()->json(['data' => $records->map(fn ($record): array => [
            'id' => $record->id,
            'name' => $record->name,
            'active' => (bool) $record->status,
        ])->values(), 'can_manage' => $manage]);
    }

    public function store(Request $request, string $type): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];
        $record = $type === 'brands'
            ? app(CreateProductBrand::class)->handle(new CreateCatalogItemData($name), $request->user())
            : app(CreateProductCategory::class)->handle(new CreateCatalogItemData($name), $request->user());

        return response()->json(['data' => ['id' => $record->id, 'name' => $record->name, 'active' => (bool) $record->status]], 201);
    }

    public function update(Request $request, string $type, int $record): JsonResponse
    {
        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];
        $model = $type === 'brands' ? ProductBrand::query()->findOrFail($record) : ProductCategory::query()->findOrFail($record);
        $model = $type === 'brands'
            ? app(RenameProductBrand::class)->handle($model, new RenameCatalogItemData($name), $request->user())
            : app(RenameProductCategory::class)->handle($model, new RenameCatalogItemData($name), $request->user());

        return response()->json(['data' => ['id' => $model->id, 'name' => $model->name, 'active' => (bool) $model->status]]);
    }

    public function status(Request $request, string $type, int $record): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:1000']]);
        $model = $type === 'brands' ? ProductBrand::query()->findOrFail($record) : ProductCategory::query()->findOrFail($record);
        $model = $type === 'brands'
            ? app(SetProductBrandStatus::class)->handle($model, new ChangeCatalogStatusData($input['active'], $input['reason']), $request->user())
            : app(SetProductCategoryStatus::class)->handle($model, new ChangeCatalogStatusData($input['active'], $input['reason']), $request->user());

        return response()->json(['data' => ['id' => $model->id, 'name' => $model->name, 'active' => (bool) $model->status]]);
    }
}
