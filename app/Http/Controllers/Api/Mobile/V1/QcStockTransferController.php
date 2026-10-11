<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\QcPermission;
use App\Enums\StockTransferPermission;
use App\Models\QcStockTransferAssignment;
use App\Models\StockTransfer;
use App\Services\Authorization\QcAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Qc\QcStockTransferAssignmentService;
use App\Services\Qc\QcStockTransferReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class QcStockTransferController extends MobileController
{
    public function show(Request $request, int $transfer): JsonResponse
    {
        $record = $this->readable($request, $transfer);
        app(QcAuthorization::class)->authorize($request->user(), QcPermission::ViewOrderAssignments);
        $readiness = app(QcStockTransferReadinessService::class)->forTransfer($record);
        $assignments = QcStockTransferAssignment::query()->where('stock_transfer_id', $record->id)
            ->with(['transferItem:id,stock_transfer_id,sku,product_name', 'device:id,reference,serial', 'certificate:id,version,snapshot,certified_at', 'certificate.inspection.warehouse:id,name'])
            ->orderBy('stock_transfer_item_id')->orderBy('assigned_at')->get();

        return response()->json(['data' => [
            'transfer' => ['id' => $record->id, 'reference' => $record->reference, 'status' => $record->status->value],
            'readiness' => $readiness,
            'assignments' => $assignments->map(fn (QcStockTransferAssignment $assignment): array => $this->presentAssignment($record, $assignment)),
        ]]);
    }

    public function verifiedScan(Request $request, int $transfer): JsonResponse
    {
        $record = $this->readable($request, $transfer);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2048'],
            'physical_serial' => ['nullable', 'string', 'max:100'],
            'stock_transfer_item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $assignment = app(QcStockTransferAssignmentService::class)->assignScan(
            $record,
            $data['code'],
            $request->user(),
            $data['physical_serial'] ?? null,
            isset($data['stock_transfer_item_id']) ? (int) $data['stock_transfer_item_id'] : null,
        );
        $assignment->load(['transferItem', 'device', 'certificate.inspection.warehouse']);

        return response()->json(['data' => [
            'matched' => [
                'stock_transfer_item_id' => $assignment->stock_transfer_item_id,
                'sku' => $assignment->transferItem->sku,
                'product' => $assignment->transferItem->product_name,
                'match_mode' => isset($data['stock_transfer_item_id']) ? 'manual' : 'auto',
            ],
            'device' => [
                'qc_id' => $assignment->device->reference,
                'serial' => $assignment->device->serial,
                'grade' => $assignment->certificate->snapshot['grade'] ?? null,
                'certificate_version' => $assignment->certificate_version,
            ],
            'verification_mode' => filled($data['physical_serial'] ?? null) ? 'qc_label_and_serial' : 'qc_label_only',
            'readiness' => app(QcStockTransferReadinessService::class)->forTransfer($record->fresh(['sourceWarehouse', 'destinationWarehouse'])),
        ]], 201);
    }

    private function readable(Request $request, int $id): StockTransfer
    {
        $transfer = StockTransfer::query()->with([
            'sourceWarehouse:id,name,code,location_type,marketplace_platform_id',
            'destinationWarehouse:id,name,code,location_type,marketplace_platform_id',
        ])->findOrFail($id);
        app(StockTransferAuthorization::class)->authorize($request->user(), StockTransferPermission::View, $transfer);

        return $transfer;
    }

    private function presentAssignment(StockTransfer $transfer, QcStockTransferAssignment $assignment): array
    {
        $snapshot = $assignment->certificate?->snapshot ?? [];
        $inspection = $assignment->certificate?->inspection;

        return [
            'stock_transfer_item_id' => $assignment->stock_transfer_item_id,
            'qc_id' => $assignment->device?->reference,
            'serial' => $assignment->device?->serial,
            'grade' => $snapshot['grade'] ?? null,
            'certificate_version' => $assignment->certificate_version,
            'source_warehouse' => $inspection?->warehouse?->name,
            'destination_marketplace_warehouse' => $transfer->destinationWarehouse?->name,
            'marketplace_platform' => $transfer->destinationWarehouse?->marketplacePlatform?->name,
            'assigned_at' => $assignment->assigned_at?->toIso8601String(),
            'dispatched_at' => $transfer->dispatched_at?->toIso8601String(),
            'received_at' => $transfer->received_at?->toIso8601String(),
            'assignment_state' => $assignment->released_at === null ? 'active' : 'released',
            'claim_state' => $assignment->active_device_id === null ? 'released' : 'active',
        ];
    }
}
