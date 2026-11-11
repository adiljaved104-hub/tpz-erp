<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcPermission;
use App\Models\QcDevice;
use App\Models\QcEvidence;
use App\Models\QcInspection;
use App\Services\Authorization\QcAuthorization;
use App\Services\BusinessTimezone;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcInspectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class QcRecordController extends MobileController
{
    public function device(Request $request, QcDevice $device, QcInspectionService $inspections, BusinessTimezone $timezone): JsonResponse
    {
        $visible = $inspections->visible($request->user())
            ->where('device_id', $device->id)
            ->with(['technician.employee:id,user_id,name', 'warehouse:id,name', 'certificate'])
            ->orderByDesc('version')
            ->get();
        abort_if($visible->isEmpty(), 404);

        return response()->json(['data' => [
            'id' => $device->id,
            'reference' => $device->reference,
            'serial' => $device->serial,
            'device_type' => $device->device_type,
            'inspections' => $visible->map(fn (QcInspection $inspection): array => [
                'id' => $inspection->id,
                'version' => $inspection->version,
                'status' => $inspection->status->value,
                'product' => data_get($inspection->product_snapshot, 'title'),
                'sku' => data_get($inspection->product_snapshot, 'sku'),
                'warehouse' => $inspection->warehouse?->name,
                'technician' => $inspection->technician?->employee?->name,
                'grade' => $inspection->grade,
                'completed_at' => $timezone->iso($inspection->completed_at),
                'certificate' => $inspection->certificate ? $this->certificateSummary($inspection->certificate) : null,
                'target' => ['module' => 'qc/inspections', 'id' => $inspection->id],
            ])->values(),
        ]]);
    }

    public function certificate(Request $request, QcInspection $inspection, QcDocumentService $documents): Response
    {
        $pdf = $documents->pdf($inspection, $request->user());

        return $pdf->download($inspection->device->reference.'-v'.$inspection->version.'.pdf');
    }

    public function evidence(Request $request, QcInspection $inspection, QcEvidence $evidence, QcInspectionService $inspections): Response
    {
        abort_unless($evidence->inspection_id === $inspection->id, 404);
        $inspection = $inspections->visible($request->user())->whereKey($inspection->id)->firstOrFail();
        $permission = $request->boolean('original') || ! $evidence->customer_visible
            ? QcPermission::ViewInternalEvidence
            : QcPermission::ViewCustomerEvidence;
        app(QcAuthorization::class)->authorize($request->user(), $permission, $inspection);
        $path = $request->boolean('original') ? $evidence->original_path : $evidence->customer_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        if ($request->boolean('original')) {
            return Storage::disk('local')->download($path, 'qc-original-image');
        }

        return response(Storage::disk('local')->get($path))
            ->header('Content-Type', 'image/jpeg')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    /** @return array<string, mixed> */
    public function certificateSummary($certificate): array
    {
        return [
            'id' => $certificate->id,
            'version' => $certificate->version,
            'current' => $certificate->isCurrent(),
            'certified_at' => $certificate->certified_at?->toIso8601String(),
            'fingerprint' => app(QcDocumentService::class)->fingerprint($certificate),
            'grade' => data_get($certificate->snapshot, 'grade'),
        ];
    }
}
