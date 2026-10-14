<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\Product;
use App\Models\QcInspection;
use App\Models\Warehouse;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Products\ProductTitleService;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionReadService;
use App\Services\Qc\QcInspectionService;
use App\Services\Qc\QcTemplateResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class QcInspectionController extends MobileController
{
    public function options(Request $request, QcAuthorization $authorization, QcTemplateResolver $templates, ProductTitleService $titles): JsonResponse
    {
        $actor = $request->user();
        $authorization->authorize($actor, QcPermission::View);
        $authorization->authorize($actor, QcPermission::Start);
        $input = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'selected_product_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $search = trim((string) ($input['search'] ?? ''));
        $products = collect();
        if ($search !== '') {
            $products = Product::query()->active()->products()
                ->where(fn ($query) => $query
                    ->where('sku', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('model', 'like', '%'.$search.'%'))
                ->with(['brandRelation', 'categoryRelation'])
                ->limit(100)
                ->get()
                ->filter(fn (Product $product) => $templates->supports($product))
                ->take(30)
                ->values();
        }
        $selected = isset($input['selected_product_id'])
            ? Product::query()->active()->products()->with(['brandRelation', 'categoryRelation'])->find($input['selected_product_id'])
            : null;
        if ($selected && ! $templates->supports($selected)) {
            $selected = null;
        }

        return response()->json(['data' => [
            'products' => $products->map(fn (Product $product): array => [
                'id' => $product->id,
                'sku' => $product->sku,
                'title' => $titles->accounting($product),
                'brand' => $product->displayBrandName(),
                'model' => $product->model,
                'condition' => $product->condition?->value,
            ])->values(),
            'warehouses' => Warehouse::query()->active()->orderBy('name')->get(['id', 'name'])->map(fn (Warehouse $warehouse): array => ['id' => $warehouse->id, 'name' => $warehouse->name]),
            'selected_product_id' => $selected?->id,
            'features' => $selected ? collect($templates->resolve($selected)['features'])->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])->values() : [],
            'default_features' => $selected ? array_values($templates->resolve($selected)['default_features']) : [],
        ]]);
    }

    public function start(Request $request, QcInspectionService $service, QcAuthorization $authorization): JsonResponse
    {
        $actor = $request->user();
        $authorization->authorize($actor, QcPermission::View);
        $authorization->authorize($actor, QcPermission::Start);
        $input = $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'serial' => ['required', 'string', 'min:3', 'max:100', 'regex:/\A[A-Za-z0-9._ -]+\z/'],
            'warehouse_id' => ['required', 'integer', 'min:1'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string'],
            'special_requirement' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $inspection = $service->start($input, $actor)->load(['device:id,reference,serial', 'warehouse:id,name']);

        return response()->json(['data' => [
            'inspection_id' => $inspection->id,
            'qc_id' => $inspection->device->reference,
            'serial' => $inspection->device->serial,
            'product' => data_get($inspection->product_snapshot, 'title'),
            'sku' => data_get($inspection->product_snapshot, 'sku'),
            'warehouse' => $inspection->warehouse?->name,
            'status' => $inspection->status->value,
        ]], 201);
    }

    public function index(Request $request, QcInspectionReadService $inspections, QcAuthorization $authorization, BusinessTimezone $timezone): JsonResponse
    {
        $input = $request->validate([
            'scope' => ['required', 'string', 'in:pending,mine,completed_today'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $actor = $request->user();
        $canViewTechnician = $authorization->allows($actor, QcPermission::ViewAll);

        $page = $inspections->query($actor, $input['scope'])
            ->with(['device:id,reference,serial', 'warehouse:id,name', 'technician.employee:id,user_id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($input['per_page'] ?? 25)
            ->through(fn (QcInspection $inspection): array => $this->present($inspection, $canViewTechnician, $timezone));

        return response()->json([...$page->toArray(), 'scope' => $input['scope'], 'timezone' => $timezone->name()]);
    }

    public function show(QcInspection $inspection, Request $request, QcInspectionService $service, QcEvidenceService $evidence, QcAuthorization $authorization, BusinessTimezone $timezone): JsonResponse
    {
        $actor = $request->user();
        $inspection = $service->visible($actor)->whereKey($inspection->id)
            ->with(['device:id,reference,serial', 'warehouse:id,name', 'checks', 'evidence', 'certificate'])
            ->firstOrFail();
        $canUpdate = $authorization->allows($actor, QcPermission::Update, $inspection);
        $canComplete = $authorization->allows($actor, QcPermission::Complete, $inspection);
        $canViewCustomerEvidence = $authorization->allows($actor, QcPermission::ViewCustomerEvidence, $inspection);
        $canViewInternal = $authorization->allows($actor, QcPermission::ViewInternalEvidence, $inspection);
        $progress = $evidence->progress($inspection, $inspection->final_configuration);

        return response()->json(['inspection' => [
            'inspection_id' => $inspection->id,
            'qc_id' => $inspection->device?->reference,
            'serial' => $inspection->device?->serial,
            'status' => $inspection->status->value,
            'version' => $inspection->version,
            'qc_source' => $inspection->qc_source,
            ...($inspection->recordedStockIntake ? ['recorded_stock_source' => [
                'grn_reference' => $inspection->recordedStockIntake->receiptItem->receipt->reference,
                'receipt_item_id' => $inspection->recordedStockIntake->purchase_receipt_item_id,
            ]] : []),
            'product_title' => data_get($inspection->product_snapshot, 'title'),
            'sku' => data_get($inspection->product_snapshot, 'sku'),
            'warehouse' => $inspection->warehouse?->name,
            'order_reference' => data_get($inspection->order_snapshot, 'reference'),
            'created_at' => $timezone->iso($inspection->created_at),
            'completed_at' => $timezone->iso($inspection->completed_at),
            'original_configuration' => Arr::only($inspection->original_configuration ?? [], ['cpu', 'ram', 'ram_mb', 'storage', 'storage_gb', 'os']),
            'requested_configuration' => Arr::only($inspection->requested_configuration ?? [], ['display_name', 'target_ram_mb', 'target_storage_total_gb', 'target_storage_layout']),
            'final_configuration' => Arr::only($inspection->final_configuration ?? [], ['cpu', 'ram_mb', 'storage_gb', 'os']),
            'features' => array_values($inspection->features ?? []),
            'grade' => $inspection->grade,
            'public_remarks' => $inspection->public_remarks,
            ...($canViewInternal ? ['internal_remarks' => $inspection->internal_remarks] : []),
            'checks' => $inspection->checks->map(fn ($check): array => [
                'key' => $check->check_key,
                'label' => $check->definition['label'] ?? $check->check_key,
                'group' => $check->definition['group'] ?? null,
                'mandatory' => (bool) ($check->definition['mandatory'] ?? false),
                'allows_na' => (bool) ($check->definition['allows_na'] ?? false),
                'applicable' => (bool) $check->applicable,
                'measurement_definition' => $check->definition['measurement'] ?? null,
                'result' => $check->result,
                'measurement' => $check->measurement,
                'notes' => $check->notes,
                'detail' => $check->detail,
            ])->values(),
            'evidence_progress' => ['required' => $progress['required'], 'completed' => $progress['complete'], 'missing' => $progress['missing']],
            'evidence' => $progress['proofs']->filter(fn ($proof) => $proof->customer_visible ? $canViewCustomerEvidence : $canViewInternal)
                ->map(fn ($proof): array => ['public_id' => $proof->public_id, 'kind' => $proof->kind, 'customer_visible' => (bool) $proof->customer_visible, 'uploaded_at' => $timezone->iso($proof->uploaded_at)])->values(),
            'can_update' => $canUpdate && $inspection->status !== QcInspectionStatus::Completed,
            'can_upload_evidence' => $canUpdate && $inspection->status !== QcInspectionStatus::Completed,
            'can_complete' => $canComplete && $inspection->status !== QcInspectionStatus::Completed,
        ]]);
    }

    public function begin(QcInspection $inspection, Request $request, QcInspectionService $service): JsonResponse
    {
        $inspection = $service->begin($inspection, $request->user());

        return response()->json(['inspection_id' => $inspection->id, 'status' => $inspection->status->value]);
    }

    public function update(QcInspection $inspection, Request $request, QcInspectionService $service, QcAuthorization $authorization): JsonResponse
    {
        $actor = $request->user();
        if (array_key_exists('internal_remarks', $request->all()) && ! $authorization->allows($actor, QcPermission::ViewInternalEvidence, $inspection)) {
            throw new AuthorizationException;
        }
        $data = Arr::only($request->all(), ['final_configuration', 'grade', 'public_remarks', 'internal_remarks', 'checks']);
        $inspection = $service->update($inspection, $data, $actor);

        return response()->json(['inspection_id' => $inspection->id, 'status' => $inspection->status->value, 'grade' => $inspection->grade,
            'final_configuration' => Arr::only($inspection->final_configuration ?? [], ['cpu', 'ram_mb', 'storage_gb', 'os']),
            'public_remarks' => $inspection->public_remarks,
            ...($authorization->allows($actor, QcPermission::ViewInternalEvidence, $inspection) ? ['internal_remarks' => $inspection->internal_remarks] : [])]);
    }

    public function evidence(QcInspection $inspection, Request $request, QcEvidenceService $evidence, BusinessTimezone $timezone): JsonResponse
    {
        $input = $request->validate(['kind' => ['required', 'string', 'in:serial,physical,display,system,upgrade,additional'], 'file' => ['required', 'image'], 'customer_visible' => ['nullable', 'boolean']]);
        $saved = $evidence->uploadMany($inspection, [$request->file('file')], $input['kind'], (bool) ($input['customer_visible'] ?? true), $request->user());
        $inspection->refresh();
        $progress = $evidence->progress($inspection, $inspection->final_configuration);
        $proof = $saved[0];

        return response()->json(['evidence' => ['public_id' => $proof->public_id, 'kind' => $proof->kind, 'customer_visible' => (bool) $proof->customer_visible, 'uploaded_at' => $timezone->iso($proof->uploaded_at)],
            'evidence_progress' => ['required' => $progress['required'], 'completed' => $progress['complete'], 'missing' => $progress['missing']]], 201);
    }

    public function complete(QcInspection $inspection, Request $request, QcInspectionService $service, BusinessTimezone $timezone): JsonResponse
    {
        $certificate = $service->complete($inspection, $request->user());
        $snapshot = $certificate->snapshot;

        return response()->json(['certificate' => ['qc_id' => $snapshot['reference'], 'serial' => $snapshot['serial'], 'version' => $certificate->version,
            'product' => data_get($snapshot, 'product.title'), 'grade' => data_get($snapshot, 'grade'),
            'certified_at' => $timezone->iso($certificate->certified_at), 'status' => 'completed', 'label_ready' => true]]);
    }

    private function present(QcInspection $inspection, bool $canViewTechnician, BusinessTimezone $timezone): array
    {
        $item = [
            'inspection_id' => $inspection->id,
            'qc_id' => $inspection->device?->reference,
            'serial' => $inspection->device?->serial,
            'product_title' => data_get($inspection->product_snapshot, 'title'),
            'sku' => data_get($inspection->product_snapshot, 'sku'),
            'status' => $inspection->status->value,
            'version' => $inspection->version,
            'qc_source' => $inspection->qc_source,
            'warehouse' => $inspection->warehouse?->name,
            'order_reference' => data_get($inspection->order_snapshot, 'reference'),
            'created_at' => $timezone->iso($inspection->created_at),
            'completed_at' => $timezone->iso($inspection->completed_at),
        ];

        if ($canViewTechnician) {
            $item['technician_name'] = $inspection->technician?->employee?->name;
        }

        return $item;
    }
}
