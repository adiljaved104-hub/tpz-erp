<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Products\CreateProduct;
use App\Actions\Products\UpdateProduct;
use App\DTOs\Products\CreateProductData;
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
        $response = $this->page($request, $this->query($request)->orderBy('name')->orderBy('id'), ['name', 'sku', 'model', 'brand', 'category'], fn ($p) => $this->present($request, $p));
        $response->setData([...$response->getData(true),
            'can_create' => app(ProductAuthorization::class)->allows($request->user(), ProductPermission::Create)]);

        return $response;
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

        $fields = $p->only(['name', 'sku', 'model', 'processor', 'processor_class', 'processor_model',
            'processor_generation', 'ram', 'storage', 'screen_size', 'graphics', 'color', 'touch_screen',
            'is_convertible_360', 'accounting_title_override', 'website_title_override', 'warranty', 'description']);
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
        $editFields = null;
        if ($auth->allows($user, ProductPermission::Update, $p)) {
            $editFields = $this->editFields($request, $p);
            $actions[] = $this->action('update', 'Update product', $editFields);
        }
        $actions[] = $this->action('intelligence', 'Product intelligence');

        return [...$data, 'fields' => $fields,
            'field_schema' => $this->readFields($fields, ['selling_price' => 'currency', 'cost_price' => 'currency', 'description' => 'multiline', 'warranty' => 'number', 'available_quantity' => 'number', 'reserved_quantity' => 'number', 'sellable_quantity' => 'number']),
            'actions' => $actions,
            'edit_values' => $editFields === null ? null : collect($editFields)->mapWithKeys(fn (array $field): array => [$field['name'] => $field['value']])->all()];
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
        foreach (['model' => 'Model', 'processor' => 'Processor', 'processor_class' => 'Processor class',
            'processor_model' => 'Processor model', 'processor_generation' => 'Processor generation', 'ram' => 'RAM',
            'storage' => 'Storage', 'screen_size' => 'Screen size', 'graphics' => 'Graphics', 'color' => 'Color'] as $key => $label) {
            $fields[] = $this->field($key, $label, 'text', false, $p->{$key});
        }
        $fields[] = $this->field('touch_screen', 'Touch screen', 'boolean', false, $p->touch_screen);
        $fields[] = $this->field('is_convertible_360', 'Convertible 360', 'boolean', false, $p->is_convertible_360);
        $fields[] = $this->field('accounting_title_override', 'Accounting title override', 'text', false, $p->accounting_title_override);
        $fields[] = $this->field('website_title_override', 'Website title override', 'text', false, $p->website_title_override);
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
        $data = $this->validatedDocument($request);
        app(UpdateProduct::class)->handle($p, new UpdateProductData(
            name: $data['name'], brandId: $data['brand_id'], categoryId: $data['category_id'],
            condition: $data['condition'], model: $data['model'] ?? null, processor: $data['processor'] ?? null,
            processorClass: $data['processor_class'] ?? null, processorModel: $data['processor_model'] ?? null,
            processorGeneration: $data['processor_generation'] ?? null, ram: $data['ram'] ?? null,
            storage: $data['storage'] ?? null, screenSize: $data['screen_size'] ?? null,
            graphics: $data['graphics'] ?? null, color: $data['color'] ?? null,
            touchScreen: array_key_exists('touch_screen', $data) ? (bool) $data['touch_screen'] : null,
            isConvertible360: array_key_exists('is_convertible_360', $data) ? (bool) $data['is_convertible_360'] : null,
            accountingTitleOverride: $data['accounting_title_override'] ?? null,
            websiteTitleOverride: $data['website_title_override'] ?? null, warranty: $data['warranty'],
            sellingPrice: isset($data['selling_price']) ? (string) $data['selling_price'] : null,
            sellingPriceProvided: array_key_exists('selling_price', $data),
            costPrice: isset($data['cost_price']) ? (string) $data['cost_price'] : null,
            costPriceProvided: array_key_exists('cost_price', $data), description: $data['description'] ?? null,
            duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
            duplicateOverrideConfirmed: (bool) ($data['duplicate_override_confirmed'] ?? false),
            responsibilityOverrideConfirmed: (bool) ($data['responsibility_override_confirmed'] ?? false),
        ), $request->user());

        return $this->show($request, $product);
    }

    public function options(Request $request): JsonResponse
    {
        $input = $request->validate(['product_id' => 'nullable|integer']);
        $product = isset($input['product_id']) ? $this->query($request)->findOrFail($input['product_id']) : null;
        app(ProductAuthorization::class)->authorize($request->user(), $product ? ProductPermission::Update : ProductPermission::Create, $product);

        return response()->json(['data' => [
            'brands' => ProductBrand::query()->where(fn ($query) => $query->where('status', true)->when($product, fn ($query) => $query->orWhere('id', $product->brand_id)))->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->where(fn ($query) => $query->where('status', true)->when($product, fn ($query) => $query->orWhere('id', $product->category_id)))->orderBy('name')->get(['id', 'name']),
            'conditions' => array_map(fn (ProductCondition $condition): array => [
                'value' => $condition->value, 'label' => $condition->label(),
            ], ProductCondition::cases()),
            'can_set_selling_price' => app(ProductAuthorization::class)->allows($request->user(), ProductPermission::EditSellingPrice),
            'can_set_cost_price' => app(ProductAuthorization::class)->allows($request->user(), ProductPermission::EditCostPrice),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        app(ProductAuthorization::class)->authorize($request->user(), ProductPermission::Create);
        $data = $this->validatedDocument($request, true);
        $product = app(CreateProduct::class)->handle(new CreateProductData(
            name: $data['name'], brandId: $data['brand_id'], categoryId: $data['category_id'],
            condition: $data['condition'], model: $data['model'] ?? null, processor: $data['processor'] ?? null,
            processorClass: $data['processor_class'] ?? null, processorModel: $data['processor_model'] ?? null,
            processorGeneration: $data['processor_generation'] ?? null, ram: $data['ram'] ?? null,
            storage: $data['storage'] ?? null, screenSize: $data['screen_size'] ?? null,
            graphics: $data['graphics'] ?? null, color: $data['color'] ?? null,
            touchScreen: array_key_exists('touch_screen', $data) ? (bool) $data['touch_screen'] : null,
            isConvertible360: array_key_exists('is_convertible_360', $data) ? (bool) $data['is_convertible_360'] : null,
            accountingTitleOverride: $data['accounting_title_override'] ?? null,
            websiteTitleOverride: $data['website_title_override'] ?? null, warranty: $data['warranty'],
            costPrice: isset($data['cost_price']) ? (string) $data['cost_price'] : null,
            costPriceProvided: array_key_exists('cost_price', $data),
            sellingPrice: isset($data['selling_price']) ? (string) $data['selling_price'] : null,
            sellingPriceProvided: array_key_exists('selling_price', $data), description: $data['description'] ?? null,
            duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
            duplicateOverrideConfirmed: (bool) ($data['duplicate_override_confirmed'] ?? false),
            responsibilityOverrideConfirmed: (bool) ($data['responsibility_override_confirmed'] ?? false),
        ), $request->user());

        return $this->show($request, $product->id);
    }

    private function validatedDocument(Request $request, bool $creating = false): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'brand_id' => ['required', 'integer', $creating ? Rule::exists('product_brands', 'id')->where('status', true) : Rule::exists('product_brands', 'id')],
            'category_id' => ['required', 'integer', $creating ? Rule::exists('product_categories', 'id')->where('status', true) : Rule::exists('product_categories', 'id')],
            'condition' => ['required', Rule::enum(ProductCondition::class)],
            'model' => ['nullable', 'string', 'max:255'],
            'processor' => ['nullable', 'string', 'max:255'],
            'processor_class' => ['nullable', 'string', 'max:255'],
            'processor_model' => ['nullable', 'string', 'max:255'],
            'processor_generation' => ['nullable', 'string', 'max:255'],
            'ram' => ['nullable', 'string', 'max:255'],
            'storage' => ['nullable', 'string', 'max:255'],
            'screen_size' => ['nullable', 'string', 'max:255'],
            'graphics' => ['nullable', 'string', 'max:255'],
            'color' => ['nullable', 'string', 'max:255'],
            'touch_screen' => ['nullable', 'boolean'],
            'is_convertible_360' => ['nullable', 'boolean'],
            'accounting_title_override' => ['nullable', 'string', 'max:255'],
            'website_title_override' => ['nullable', 'string', 'max:255'],
            'warranty' => ['required', 'integer', 'min:0', 'max:600'],
            'description' => ['nullable', 'string', 'max:10000'],
            'selling_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'duplicate_override_reason' => ['nullable', 'string', 'max:500'],
            'duplicate_override_confirmed' => ['nullable', 'boolean'],
            'responsibility_override_confirmed' => ['nullable', 'boolean'],
        ]);
    }
}
