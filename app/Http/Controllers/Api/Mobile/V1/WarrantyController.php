<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Models\WarrantyRepair;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use App\Services\ServiceCases\WarrantySlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $auth = app(WarrantyRepairAuthorization::class);
        $auth->authorize($request->user(), WarrantyRepairPermission::View);

        $query = $auth->scopeQuery(WarrantyRepair::query(), $request->user())
            ->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name'])
            ->orderByDesc('id');
        if ($request->input('type') === 'external') {
            $query->externalService();
        }
        if ($request->input('filter') === 'open') {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        return $this->page($request, $query, ['reference', 'issue_description', 'notes'], fn ($repair) => $this->present($request, $repair));
    }

    public function internalRepairs(Request $request): JsonResponse
    {
        $auth = app(WarrantyRepairAuthorization::class);
        $auth->authorize($request->user(), WarrantyRepairPermission::View);
        $query = $auth->scopeQuery(WarrantyRepair::query(), $request->user())
            ->internalCompanyOwned()
            ->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name'])
            ->orderByDesc('id');
        if ($request->input('filter') === 'open') {
            $query->whereNotIn('status', ['completed', 'cancelled']);
        }

        return $this->page($request, $query, ['reference', 'issue_description', 'notes'], fn ($repair) => $this->present($request, $repair));
    }

    public function show(Request $request, WarrantyRepair $warranty): JsonResponse
    {
        app(WarrantyRepairAuthorization::class)->authorize($request->user(), WarrantyRepairPermission::View, $warranty);
        $warranty->loadMissing(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name', 'order:id,reference', 'warehouse:id,name']);

        return response()->json(['data' => $this->present($request, $warranty, true)]);
    }

    public function act(Request $request, WarrantyRepair $warranty, string $action): JsonResponse
    {
        app(WarrantyRepairAuthorization::class)->authorize($request->user(), WarrantyRepairPermission::View, $warranty);
        $data = $request->validate([
            'note' => 'nullable|string|max:5000',
            'notes' => 'nullable|string|max:5000',
            'service_provider' => 'nullable|string|max:255',
            'expected_return_at' => 'nullable|date',
            'assigned_to_user_id' => 'nullable|integer|exists:users,id',
        ]);

        try {
            if ($action === 'assign') {
                $result = app(ServiceCaseAssigneeService::class)->assignWarranty(
                    $warranty,
                    filled($data['assigned_to_user_id'] ?? null) ? (int) $data['assigned_to_user_id'] : null,
                    $request->user(),
                );
            } elseif ($action === 'update') {
                $result = app(WarrantyRepairService::class)->updateOperationalDetails(
                    $warranty,
                    collect($data)->only(['notes', 'service_provider', 'expected_return_at'])->all(),
                    $request->user(),
                );
            } else {
                $to = WarrantyRepairStatus::tryFrom($action);
                abort_if($to === null, 404);
                $result = app(WarrantyRepairService::class)->transition($warranty, $to, $request->user(), $data['note'] ?? null);
            }
        } catch (WarrantyRepairException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->show($request, $result);
    }

    private function present(Request $request, WarrantyRepair $repair, bool $detail = false): array
    {
        $sla = app(WarrantySlaService::class);
        $data = [
            'id' => $repair->id,
            'title' => $repair->reference,
            'subtitle' => $repair->product?->name,
            'meta' => 'SLA: '.$sla->daysLeftLabel($repair),
            'status' => $repair->status->value,
        ];
        if (! $detail) {
            return $data;
        }

        $auth = app(WarrantyRepairAuthorization::class);
        $user = $request->user();
        $actions = [];
        $lifecycle = app(WarrantyRepairLifecycleService::class);
        foreach ($lifecycle->validTransitions($repair) as $to) {
            $permission = $to === WarrantyRepairStatus::UnderInspection
                ? WarrantyRepairPermission::Inspect
                : WarrantyRepairPermission::UpdateStatus;
            if ($auth->allows($user, $permission, $repair)) {
                $actions[] = $this->action($to->value, $lifecycle->actionLabel($to, $repair), [
                    $this->field('note', 'Remarks / reason', 'multiline', $to === WarrantyRepairStatus::CannotRepair),
                ]);
            }
        }
        if ($auth->allows($user, WarrantyRepairPermission::UpdateStatus, $repair)) {
            $actions[] = $this->action('update', 'Update service details', [
                $this->field('notes', 'Remarks', 'multiline', false, $repair->notes),
                $this->field('service_provider', 'Service provider', 'text', false, $repair->service_provider),
                $this->field('expected_return_at', 'Expected return (YYYY-MM-DD)', 'date', false, $repair->expected_return_at?->format('Y-m-d')),
            ]);
        }
        if ($auth->allows($user, WarrantyRepairPermission::Assign, $repair)) {
            $options = collect(app(ServiceCaseAssigneeService::class)->warrantyOptions($repair))
                ->map(fn ($label, $id) => ['value' => $id, 'label' => $label])->values()->all();
            $actions[] = $this->action('assign', 'Assign repair', [
                $this->field('assigned_to_user_id', 'Assigned employee', 'select', false, $repair->assigned_to_user_id, $options),
            ]);
        }

        return [...$data, 'fields' => [
            'product_sku' => $repair->product?->sku,
            'source' => $repair->source?->value,
            'platform' => $repair->platform?->name,
            'order_reference' => $repair->order?->reference,
            'warehouse' => $repair->warehouse?->name,
            'assigned_to' => $repair->assignedTo?->name,
            'issue_description' => $repair->issue_description,
            'quantity' => $repair->quantity,
            'notes' => $repair->notes,
            'received_at' => $repair->received_at?->toIso8601String(),
            'expected_return_at' => $repair->expected_return_at?->toIso8601String(),
            'sla_due_at' => $sla->dueAt($repair)->toIso8601String(),
            'sla_status' => $sla->status($repair),
            'service_provider' => $repair->service_provider,
        ], 'actions' => $actions];
    }
}
