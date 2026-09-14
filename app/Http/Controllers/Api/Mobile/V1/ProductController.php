<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\UpdateProductData;
use App\Enums\ProductPermission;
use App\Enums\ResponsibilityPermission;
use App\Models\Product;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Mobile\MobileInventoryService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends MobileController
{
    private function query(Request $request): Builder
    {
        abort_unless(app(ProductAuthorization::class)->allows($request->user(), ProductPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($request->user(), ResponsibilityPermission::ViewOwn), 403);
        $ids = DB::table('products')->select('products.id');
        $scope = app(ResponsibilityProductScopeService::class);
        // Inventory visibility includes platform-only assignments.
        if ($scope->requiresScope($request->user())) {
            $ids->where(function ($q) use ($request): void {
                $q->whereIn('id', app(MobileInventoryService::class)->query($request->user())->select('p.id'));
            });
        }

        return Product::query()->whereIn('id', $ids)->with(['brandRelation', 'categoryRelation']);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->page($request, $this->query($request)->orderBy('name')->orderBy('id'), ['name', 'sku', 'model', 'brand', 'category'], fn ($p) => $this->present($request, $p));
    }

    public function show(Request $request, int $product): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->query($request)->findOrFail($product), true)]);
    }

    private function present(Request $request, Product $p, bool $detail = false): array
    {
        $auth = app(ProductAuthorization::class);
        $data = ['id' => $p->id, 'title' => $p->name, 'subtitle' => $p->sku.' · '.$p->displayBrandName(), 'meta' => $p->displayCategoryName().' · '.$p->model, 'status' => $p->status->value];
        if (! $detail) {
            return $data;
        }
        $fields = $p->only(['name', 'sku', 'model', 'processor', 'ram', 'storage', 'screen_size', 'graphics', 'color', 'warranty', 'description']);
        $fields['condition'] = $p->condition->value;
        $fields['brand'] = $p->displayBrandName();
        $fields['category'] = $p->displayCategoryName();
        if ($auth->allows($request->user(), ProductPermission::ViewSellingPrice, $p)) {
            $fields['selling_price'] = $p->selling_price;
        }
        if ($auth->allows($request->user(), ProductPermission::ViewCostPrice, $p)) {
            $fields['cost_price'] = $p->cost_price;
        }
        $stock = app(MobileInventoryService::class)->query($request->user())->where('p.id', $p->id)->selectRaw('COALESCE(SUM(pi.available_quantity),0) available, COALESCE(SUM(pi.reserved_quantity),0) reserved')->first();
        $fields['available_quantity'] = (int) $stock->available;
        $fields['reserved_quantity'] = (int) $stock->reserved;
        $fields['sellable_quantity'] = max(0, (int) $stock->available - (int) $stock->reserved);
        $actions = [];
        if ($auth->allows($request->user(), ProductPermission::Update, $p)) {
            $actions[] = $this->action('update', 'Update product', [
                $this->field('name', 'Product name', 'text', true, $p->name),
                $this->field('description', 'Description', 'multiline', false, $p->description),
            ]);
        }

        return [...$data, 'fields' => $fields, 'actions' => $actions];
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $p = $this->query($request)->findOrFail($product);
        $data = $request->validate(['name' => 'required|string|max:255', 'description' => 'nullable|string|max:10000']);
        app(UpdateProduct::class)->handle($p, new UpdateProductData(
            name: $data['name'], brandId: $p->brand_id, categoryId: $p->category_id, condition: $p->condition,
            model: $p->model, processor: $p->processor, ram: $p->ram, storage: $p->storage, screenSize: $p->screen_size,
            graphics: $p->graphics, color: $p->color, warranty: $p->warranty, description: $data['description'] ?? null,
        ), $request->user());

        return $this->show($request, $product);
    }
}
