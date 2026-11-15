<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ComponentPermission;
use App\Enums\ComponentType;
use App\Enums\ProductStatus;
use App\Models\Component;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Services\Authorization\ComponentAuthorization;
use App\Services\Components\ComponentCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComponentController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(ComponentAuthorization::class)->authorize($request->user(), ComponentPermission::View);
        $query = Component::query()->with('product.brandRelation', 'product.categoryRelation')->orderBy('id');

        $response = $this->page($request, $query, ['specification'], fn (Component $component): array => $this->present($request, $component));
        $response->setData([...$response->getData(true),
            'can_create' => app(ComponentAuthorization::class)->allows($request->user(), ComponentPermission::Create)]);

        return $response;
    }

    public function show(Request $request, int $component): JsonResponse
    {
        $component = Component::query()->with('product.brandRelation', 'product.categoryRelation')->findOrFail($component);
        app(ComponentAuthorization::class)->authorize($request->user(), ComponentPermission::View, $component);

        return response()->json(['data' => $this->present($request, $component, true)]);
    }

    public function options(Request $request): JsonResponse
    {
        $input = $request->validate(['component_id' => 'nullable|integer']);
        $component = isset($input['component_id']) ? Component::query()->with('product')->findOrFail($input['component_id']) : null;
        app(ComponentAuthorization::class)->authorize($request->user(), $component ? ComponentPermission::Update : ComponentPermission::Create, $component);

        return response()->json(['data' => [
            'brands' => ProductBrand::query()->where(fn ($query) => $query->where('status', true)->when($component, fn ($query) => $query->orWhere('id', $component->product->brand_id)))->orderBy('name')->get(['id', 'name']),
            'categories' => ProductCategory::query()->where(fn ($query) => $query->where('status', true)->when($component, fn ($query) => $query->orWhere('id', $component->product->category_id)))->orderBy('name')->get(['id', 'name']),
            'types' => collect(ComponentType::cases())->map(fn (ComponentType $type): array => ['value' => $type->value, 'label' => $type->label()])->values(),
            'can_approve_recovery_value' => app(ComponentAuthorization::class)->allows($request->user(), ComponentPermission::ApproveRecoveryValue),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $component = app(ComponentCatalogService::class)->create($request->all(), $request->user());

        return $this->show($request, $component->id);
    }

    public function update(Request $request, int $component): JsonResponse
    {
        $component = Component::query()->findOrFail($component);
        app(ComponentCatalogService::class)->update($component, $request->all(), $request->user());

        return $this->show($request, $component->id);
    }

    public function act(Request $request, int $component, string $action): JsonResponse
    {
        $component = Component::query()->findOrFail($component);
        $service = app(ComponentCatalogService::class);
        match ($action) {
            'activate' => $service->changeStatus($component, ProductStatus::Active, $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason'], $request->user()),
            'deactivate' => $service->changeStatus($component, ProductStatus::Inactive, $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason'], $request->user()),
            'discontinue' => $service->changeStatus($component, ProductStatus::Discontinued, $request->validate(['reason' => 'required|string|min:5|max:1000'])['reason'], $request->user()),
            'approve-recovery' => $service->updateApprovedRecoveryValue($component,
                (string) $request->validate(['value' => 'required|numeric|min:0', 'reason' => 'required|string|min:5|max:1000'])['value'],
                (string) $request->input('reason'), $request->user()),
            default => abort(404),
        };

        return $this->show($request, $component->id);
    }

    private function present(Request $request, Component $component, bool $detail = false): array
    {
        $product = $component->product;
        $data = [
            'id' => $component->id,
            'title' => $product->name,
            'subtitle' => $product->sku.' · '.$component->component_type->label(),
            'meta' => $component->specification,
            'status' => $product->status->value,
        ];
        if (! $detail) {
            return $data;
        }

        $auth = app(ComponentAuthorization::class);
        $fields = [
            'sku' => $product->sku,
            'brand' => $product->displayBrandName(),
            'category' => $product->displayCategoryName(),
            'model' => $product->model,
            'component_type' => $component->component_type->value,
            'specification' => $component->specification,
            'capacity_value' => $component->capacity_value,
            'capacity_unit' => $component->capacity_unit,
            'interface_type' => $component->interface_type,
            'description' => $product->description,
        ];
        if ($auth->allows($request->user(), ComponentPermission::ViewRecoveryValue, $component)) {
            $fields['approved_oem_recovery_value'] = $component->approved_oem_recovery_value;
            $fields['recovery_reason'] = $component->recovery_reason;
        }

        $actions = [];
        if ($auth->allows($request->user(), ComponentPermission::Update, $component)) {
            $actions[] = $this->action('edit', 'Edit component');
        }
        if ($auth->allows($request->user(), ComponentPermission::ChangeStatus, $component)) {
            foreach (ProductStatus::cases() as $status) {
                if ($status === $product->status) {
                    continue;
                }
                $actions[] = $this->action($status === ProductStatus::Active ? 'activate' : ($status === ProductStatus::Inactive ? 'deactivate' : 'discontinue'),
                    $status->label().' component', [$this->field('reason', 'Reason', 'multiline', true)]);
            }
        }
        if ($auth->allows($request->user(), ComponentPermission::ApproveRecoveryValue, $component)) {
            $actions[] = $this->action('approve-recovery', 'Approve recovery value', [
                $this->field('value', 'Approved OEM recovery value (AED)', 'number', true, $component->approved_oem_recovery_value),
                $this->field('reason', 'Reason', 'multiline', true),
            ]);
        }

        return [...$data, 'fields' => $fields, 'field_schema' => $this->readFields($fields, [
            'approved_oem_recovery_value' => 'currency', 'capacity_value' => 'number', 'description' => 'multiline',
        ]), 'actions' => $actions, 'edit_values' => $auth->allows($request->user(), ComponentPermission::Update, $component) ? [
            'name' => $product->name, 'brand_id' => $product->brand_id, 'category_id' => $product->category_id,
            'model' => $product->model, 'description' => $product->description, 'component_type' => $component->component_type->value,
            'specification' => $component->specification, 'capacity_value' => $component->capacity_value,
            'capacity_unit' => $component->capacity_unit, 'interface_type' => $component->interface_type,
        ] : null];
    }
}
