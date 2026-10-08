<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcPermission;
use App\Models\QcInspection;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcInspectionReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
