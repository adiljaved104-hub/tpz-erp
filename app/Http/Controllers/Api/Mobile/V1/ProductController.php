<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\UpdateProductData;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\ResponsibilityPermission;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Mobile\MobileInventoryService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends MobileController
{
    private function query(Request $request): Builder
    {
        abort_unless(app(ProductAuthorization::class)->allows($request->user(), ProductPermission::View)
            || app(ResponsibilityAuthorization::class)->allows($request->user(), ResponsibilityPermission::ViewOwn), 403);
        $ids = DB::table('products')->select('products.id');
        $scope = app(ResponsibilityProductScopeService::class);
        if ($scope->requiresScope($request->user())) {
            $ids->whereIn('id', app(MobileInventoryService::class)->query($request->user())->select('p.id'));
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
        $user = $request->user();
        $data = ['id' => $p->id, 'title' => $p->name, 'subtitle' => $p->sku.' · '.$p->displayBrandName(),
            'meta' => $p->displayCategoryName().' · '.$p->model, 'status' => $p->status->value];
        if (! $detail) {
            return $data;
        }

        $fields = $p->only(['name', 'sku', 'model', 'processor', 'ram', 'storage', 'screen_size', 'graphics', 'color', 'warranty', 'description']);
        $fields['condition'] = $p->condition->value;
        $fields['brand'] = $p->displayBrandName();
        $fields['category'] = $p->displayCategoryName();
        if ($auth->allows($user, ProductPermission::ViewSellingPrice, $p)) {
            $fields['selling_price'] = $p->selling_price;
        }
        if ($auth->allows($user, ProductPermission::ViewCostPrice, $p)) {
            $fields['cost_price'] = $p->cost_price;
        }

        $stock = app(MobileInventoryService::class)->query($user)->where('p.id', $p->id)
            ->selectRaw('COALESCE(SUM(pi.available_quantity),0) available, COALESCE(SUM(pi.reserved_quantity),0) reserved')->first();
        $fields['available_quantity'] = (int) $stock->available;
        $fields['reserved_quantity'] = (int) $stock->reserved;
        $fields['sellable_quantity'] = max(0, (int) $stock->available - (int) $stock->reserved);

        $actions = [];
        if ($auth->allows($user, ProductPermission::Update, $p)) {
            $actions[] = $this->action('update', 'Update product', $this->editFields($request, $p));
        }

        return [...$data, 'fields' => $fields, 'actions' => $actions];
    }

    private function editFields(Request $request, Product $p): array
    {
        $brandOptions = ProductBrand::query()->where(fn ($q) => $q->where('status', true)->orWhere('id', $p->brand_id))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => ['value' => $row->id, 'label' => $row->name])->all();
        $categoryOptions = ProductCategory::query()->where(fn ($q) => $q->where('status', true)->orWhere('id', $p->category_id))
            ->orderBy('name')->get(['id', 'name'])->map(fn ($row) => ['value' => $row->id, 'label' => $row->name])->all();
        $fields = [
            $this->field('name', 'Product name', 'text', true, $p->name),
            $this->field('brand_id', 'Brand', 'select', true, $p->brand_id, $brandOptions),
            $this->field('category_id', 'Category', 'select', true, $p->category_id, $categoryOptions),
            $this->field('condition', 'Condition', 'select', true, $p->condition->value,
                array_map(fn (ProductCondition $condition) => ['value' => $condition->value, 'label' => $condition->label()], ProductCondition::cases())),
        ];
        foreach (['model' => 'Model', 'processor' => 'Processor', 'ram' => 'RAM', 'storage' => 'Storage',
            'screen_size' => 'Screen size', 'graphics' => 'Graphics', 'color' => 'Color'] as $key => $label) {
            $fields[] = $this->field($key, $label, 'text', false, $p->{$key});
        }
        $fields[] = $this->field('warranty', 'Warranty (months)', 'number', true, $p->warranty);
        $auth = app(ProductAuthorization::class);
        $user = $request->user();
        if ($auth->allows($user, ProductPermission::ViewSellingPrice, $p)
            && $auth->allows($user, ProductPermission::EditSellingPrice, $p)) {
            $fields[] = $this->field('selling_price', 'Selling price (AED)', 'number', true, $p->selling_price);
        }
        if ($auth->allows($user, ProductPermission::ViewCostPrice, $p)
            && $auth->allows($user, ProductPermission::EditCostPrice, $p)) {
            $fields[] = $this->field('cost_price', 'Cost price (AED)', 'number', false, $p->cost_price);
        }
        $fields[] = $this->field('description', 'Description', 'multiline', false, $p->description);

        return $fields;
    }

    public function update(Request $request, int $product): JsonResponse
    {
        $p = $this->query($request)->findOrFail($product);
        app(ProductAuthorization::class)->authorize($request->user(), ProductPermission::Update, $p);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', Rule::exists('product_brands', 'id')],
            'category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'model' => ['nullable', 'string', 'max:255'],
            'processor' => ['nullable', 'string', 'max:255'],
            'ram' => ['nullable', 'string', 'max:255'],
            'storage' => ['nullable', 'string', 'max:255'],
            'screen_size' => ['nullable', 'string', 'max:255'],
            'graphics' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'warranty' => ['required', 'integer', 'min:0', 'max:600'],
            'description' => ['nullable', 'string', 'max:10000'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duplicate_override_reason' => ['nullable', 'string', 'max:500'],
        ]);
        app(UpdateProduct::class)->handle($p, new UpdateProductData(
            name: $data['name'], brandId: $data['brand_id'], categoryId: $data['category_id'],
            condition: $data['condition'], model: $data['model'] ?? null, processor: $data['processor'] ?? null,
            ram: $data['ram'] ?? null, storage: $data['storage'] ?? null, screenSize: $data['screen_size'] ?? null,
            graphics: $data['graphics'] ?? null, color: $data['color'] ?? null, warranty: $data['warranty'],
            sellingPrice: isset($data['selling_price']) ? (string) $data['selling_price'] : null,
            sellingPriceProvided: array_key_exists('selling_price', $data),
            costPrice: isset($data['cost_price']) ? (string) $data['cost_price'] : null,
            costPriceProvided: array_key_exists('cost_price', $data),
            description: $data['description'] ?? null, duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
        ), $request->user());

        return $this->show($request, $product);
    }
}
