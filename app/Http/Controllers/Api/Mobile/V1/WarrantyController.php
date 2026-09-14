<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\WarrantyRepairPermission;
use App\Enums\WarrantyRepairStatus;
use App\Models\WarrantyRepair;
use App\Services\Authorization\WarrantyRepairAuthorization;
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

        return $this->page($request, $auth->scopeQuery(WarrantyRepair::query(), $request->user())->with('product')->orderByDesc('id'),
            ['reference', 'issue_description', 'notes'], fn ($r) => $this->present($request, $r));
    }

    public function show(Request $request, WarrantyRepair $warranty): JsonResponse
    {
        app(WarrantyRepairAuthorization::class)->authorize($request->user(), WarrantyRepairPermission::View, $warranty);

        return response()->json(['data' => $this->present($request, $warranty, true)]);
    }

    private function present(Request $request, WarrantyRepair $r, bool $detail = false): array
    {
        $sla = app(WarrantySlaService::class);
        $data = ['id' => $r->id, 'title' => $r->reference, 'subtitle' => $r->product?->name, 'meta' => 'SLA: '.$sla->daysLeftLabel($r), 'status' => $r->status->value];
        if (! $detail) {
            return $data;
        }
        $auth = app(WarrantyRepairAuthorization::class);
        $user = $request->user();
        $actions = [];
        $lifecycle = app(WarrantyRepairLifecycleService::class);
        foreach ($lifecycle->validTransitions($r) as $to) {
            $permission = $to === WarrantyRepairStatus::UnderInspection ? WarrantyRepairPermission::Inspect : WarrantyRepairPermission::UpdateStatus;
            if ($auth->allows($user, $permission, $r)) {
                $actions[] = $this->action($to->value, $lifecycle->actionLabel($to, $r), [$this->field('note', 'Remarks / reason', 'multiline', $to === WarrantyRepairStatus::CannotRepair)]);
            }
        }
        if ($auth->allows($user, WarrantyRepairPermission::UpdateStatus, $r)) {
            $actions[] = $this->action('update', 'Update service details', [
                $this->field('notes', 'Remarks', 'multiline', false, $r->notes),
                $this->field('service_provider', 'Service provider', 'text', false, $r->service_provider),
                $this->field('expected_return_at', 'Expected return (YYYY-MM-DD)', 'date', false, $r->expected_return_at?->format('Y-m-d')),
            ]);
        }

        return [...$data, 'fields' => ['issue_description' => $r->issue_description, 'quantity' => $r->quantity, 'notes' => $r->notes,
            'received_at' => $r->received_at?->toIso8601String(), 'expected_return_at' => $r->expected_return_at?->toIso8601String(),
            'sla_due_at' => $sla->dueAt($r)->toIso8601String(), 'sla_status' => $sla->status($r), 'service_provider' => $r->service_provider],
            'actions' => $actions];
    }

    public function act(Request $request, WarrantyRepair $warranty, string $action): JsonResponse
    {
        app(WarrantyRepairAuthorization::class)->authorize($request->user(), WarrantyRepairPermission::View, $warranty);
        $d = $request->validate(['note' => 'nullable|string|max:5000', 'notes' => 'nullable|string|max:5000',
            'service_provider' => 'nullable|string|max:255', 'expected_return_at' => 'nullable|date']);
        $service = app(WarrantyRepairService::class);
        if ($action === 'update') {
            $result = $service->updateOperationalDetails($warranty, collect($d)->only(['notes', 'service_provider', 'expected_return_at'])->all(), $request->user());
        } else {
            $to = WarrantyRepairStatus::tryFrom($action);
            abort_if($to === null, 404);
            $result = $service->transition($warranty, $to, $request->user(), $d['note'] ?? null);
        }

        return $this->show($request, $result);
    }
}
