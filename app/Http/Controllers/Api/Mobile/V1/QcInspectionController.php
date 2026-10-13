<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\QcInspection;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcEvidenceService;
use App\Services\Qc\QcInspectionReadService;
use App\Services\Qc\QcInspectionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class QcInspectionController extends MobileController
{
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
            'certified_at' => $timezone->iso($certificate->certified_at), 'status' => 'completed']]);
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
