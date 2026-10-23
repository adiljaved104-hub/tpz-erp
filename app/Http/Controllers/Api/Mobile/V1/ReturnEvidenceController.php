<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnItem;
use App\Models\QcOrderAssignment;
use App\Services\Returns\CustomerReturnEvidenceAuthorization;
use App\Services\Returns\CustomerReturnEvidenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReturnEvidenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $term = trim($data['q']);
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query = app(CustomerReturnEvidenceAuthorization::class)->eligible($request->user())
            ->whereHas('order', fn ($orders) => $orders->where('reference', 'like', $like)
                ->orWhere('external_order_number', 'like', $like)
                ->when(ctype_digit($term), fn ($numbers) => $numbers->orWhere('id', (int) $term)))
            ->with('platform:id,name')
            ->orderByDesc('id');

        return response()->json($query->paginate(20)->through(fn (CustomerReturn $return) => [
            'id' => $return->id, 'return_reference' => $return->reference,
            'order_id' => $return->order_id, 'marketplace' => $return->platform?->name,
            'status' => $return->status->value,
        ]));
    }

    public function show(Request $request, CustomerReturn $return): JsonResponse
    {
        app(CustomerReturnEvidenceAuthorization::class)->authorizeView($request->user(), $return);
        abort_unless($return->received_at !== null, 404);
        $return->load(['order:id,reference,external_order_number', 'platform:id,name',
            'items.product:id,condition', 'items.findings.evidence']);

        return response()->json(['data' => [
            'id' => $return->id, 'return_reference' => $return->reference,
            'order_id' => $return->order_id, 'order_reference' => $return->order?->reference,
            'marketplace_order_id' => $return->order?->external_order_number,
            'marketplace' => $return->platform?->name, 'status' => $return->status->value,
            'can_record' => app(CustomerReturnEvidenceAuthorization::class)->canRecord($request->user(), $return),
            'items' => $return->items->map(fn (CustomerReturnItem $item) => [
                'id' => $item->id, 'original_product' => $item->product_name_snapshot,
                'original_sku' => $item->sku_snapshot, 'original_serial' => $this->originalSerial($item, $return->order_id),
                'sale_condition' => $item->product?->condition?->value ?? $item->product?->condition,
                'return_reason' => $item->return_reason->value,
                'findings' => $item->findings->map(fn ($finding) => [
                    'id' => $finding->id, 'problem_type' => $finding->problem_type,
                    'actual_product_name' => $finding->actual_product_name,
                    'actual_serial_number' => $finding->actual_serial_number,
                    'physical_condition' => $finding->physical_condition, 'remarks' => $finding->remarks,
                    'recorded_at' => $finding->recorded_at?->toIso8601String(),
                    'evidence' => $finding->evidence->map(fn ($evidence) => $this->present($evidence)),
                ]),
            ]),
        ]]);
    }

    public function store(Request $request, CustomerReturn $return, CustomerReturnItem $item): JsonResponse
    {
        abort_unless($item->customer_return_id === $return->id, 404);
        app(CustomerReturnEvidenceAuthorization::class)->authorizeRecord($request->user(), $return);
        $request->validate(['file' => ['required', 'file']]);
        $evidence = app(CustomerReturnEvidenceService::class)->record($item, $request->user(), $request->except('file'), $request->file('file'));

        return response()->json(['data' => $this->present($evidence), 'message' => 'Evidence saved on the server.']);
    }

    public function original(Request $request, CustomerReturnEvidence $evidence): BinaryFileResponse
    {
        $return = $evidence->finding->item->customerReturn;
        app(CustomerReturnEvidenceAuthorization::class)->authorizeView($request->user(), $return);
        abort_unless(Storage::disk('local')->exists($evidence->original_path), 404);

        return response()->download(Storage::disk('local')->path($evidence->original_path), $evidence->original_name,
            ['Content-Type' => $evidence->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    public function preview(Request $request, CustomerReturnEvidence $evidence): BinaryFileResponse
    {
        $return = $evidence->finding->item->customerReturn;
        app(CustomerReturnEvidenceAuthorization::class)->authorizeView($request->user(), $return);
        abort_unless(Storage::disk('local')->exists($evidence->original_path), 404);

        return response()->file(Storage::disk('local')->path($evidence->original_path), [
            'Content-Type' => $evidence->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function present(CustomerReturnEvidence $evidence): array
    {
        return [
            'id' => $evidence->id, 'media_type' => $evidence->media_type,
            'mime_type' => $evidence->mime_type, 'original_name' => $evidence->original_name,
            'byte_size' => $evidence->byte_size, 'duration_seconds' => $evidence->duration_seconds,
            'uploaded_at' => $evidence->uploaded_at?->toIso8601String(),
            'original_url' => '/api/mobile/v1/workspace/qc/return-evidence/files/'.$evidence->id,
        ];
    }

    private function originalSerial(CustomerReturnItem $item, int $orderId): ?string
    {
        // Only a single-unit, uniquely assigned QC device can be attributed to
        // this returned unit. Multi-unit orders have no return-to-serial mapping.
        if ($item->return_quantity !== 1 || $item->fulfilled_quantity_snapshot !== 1) {
            return null;
        }

        $assignments = QcOrderAssignment::query()
            ->where('order_item_id', $item->order_item_id)
            ->where('order_id', $orderId)
            ->whereNull('released_at')
            ->with('device:id,serial')
            ->limit(2)->get();

        return $assignments->count() === 1 ? $assignments->first()->device?->serial : null;
    }
}
