<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\CustomerReturnInspectionResult;
use App\Http\Controllers\Controller;
use App\Models\CustomerReturn;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnItem;
use App\Models\CustomerReturnVideoUploadSession;
use App\Models\QcOrderAssignment;
use App\Services\Returns\CustomerReturnEvidenceAuthorization;
use App\Services\Returns\CustomerReturnEvidenceService;
use App\Services\Returns\CustomerReturnVideoUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReturnEvidenceController extends Controller
{
    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $eligible = app(CustomerReturnEvidenceAuthorization::class)->eligible($request->user())->select('customer_returns.id');
        $items = CustomerReturnItem::query()->whereIn('customer_return_id', $eligible);
        $waiting = (clone $items)->whereDoesntHave('findings.evidence');
        $uploaded = (clone $items)->whereHas('findings.evidence')->count();
        $needsReview = app(CustomerReturnEvidenceAuthorization::class)->eligible($request->user())
            ->where(function ($query): void {
                $query->whereHas('quarantines', fn ($quarantines) => $quarantines->whereNull('reviewed_at'))
                    ->orWhereHas('items', fn ($items) => $items
                        ->whereHas('inspections')
                        ->whereHas('findings', fn ($findings) => $findings->whereIn('problem_type', ['wrong_item', 'serial_mismatch', 'empty_package'])))
                    ->orWhereHas('items', fn ($items) => $items
                        ->whereHas('inspections', fn ($inspections) => $inspections->where('result', 'sellable'))
                        ->whereDoesntHave('inspections', fn ($inspections) => $inspections->where('result', 'damaged'))
                        ->whereHas('findings', fn ($findings) => $findings->whereIn('problem_type', ['damaged', 'defective', 'missing_accessories'])))
                    ->orWhereHas('items', fn ($items) => $items
                        ->whereHas('inspections', fn ($inspections) => $inspections->where('result', 'damaged'))
                        ->whereDoesntHave('inspections', fn ($inspections) => $inspections->where('result', 'sellable'))
                        ->whereHas('findings', fn ($findings) => $findings->where('problem_type', 'sellable')));
            })->count();
        $pending = (clone $waiting)->with(['customerReturn.order:id,reference', 'inspections', 'removalItems.inspections'])
            ->orderByDesc('id')->paginate(20);

        return response()->json([
            'returns_waiting_for_evidence' => (clone $waiting)->count(),
            'evidence_uploaded' => $uploaded,
            'returns_needing_review' => $needsReview,
            'pending' => $pending->through(fn (CustomerReturnItem $item) => [
                'return_id' => $item->customer_return_id,
                'item_id' => $item->id,
                'return_reference' => $item->customerReturn->reference,
                'order_reference' => $item->customerReturn->order?->reference,
                'product' => $item->product_name_snapshot,
                'sku' => $item->sku_snapshot,
                'return_reason' => $item->return_reason->value,
                'qc_result' => $this->qcSummary($item)['result'],
                'evidence_status' => 'Evidence Pending',
            ]),
        ]);
    }

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
            'items.product:id,condition', 'items.findings.evidence.label', 'items.inspections', 'items.removalItems.inspections']);
        $videoParts = $return->items->flatMap(fn (CustomerReturnItem $item) => $item->findings
            ->flatMap(fn ($finding) => $finding->evidence))
            ->where('media_type', 'video')->sortBy('id')->values()
            ->mapWithKeys(fn ($evidence, $index) => [$evidence->id => $index + 1]);

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
                'return_quantity' => $item->return_quantity,
                'qc_result' => $this->qcSummary($item)['result'],
                'inspected_quantity' => $this->qcSummary($item)['inspected_quantity'],
                'sellable_quantity' => $this->qcSummary($item)['sellable_quantity'],
                'damaged_quantity' => $this->qcSummary($item)['damaged_quantity'],
                'findings' => $item->findings->map(fn ($finding) => [
                    'id' => $finding->id, 'problem_type' => $finding->problem_type,
                    'actual_product_name' => $finding->actual_product_name,
                    'actual_serial_number' => $finding->actual_serial_number,
                    'physical_condition' => $finding->physical_condition, 'remarks' => $finding->remarks,
                    'recorded_at' => $finding->recorded_at?->toIso8601String(),
                    'evidence' => $finding->evidence->map(fn ($evidence) => $this->present($evidence, $videoParts[$evidence->id] ?? null)),
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

    public function startVideo(Request $request, CustomerReturn $return, CustomerReturnItem $item): JsonResponse
    {
        $session = app(CustomerReturnVideoUploadService::class)->start($return, $item, $request->user(), $request->all());

        return response()->json(['data' => app(CustomerReturnVideoUploadService::class)->status($session, $request->user())]);
    }

    public function videoStatus(Request $request, CustomerReturnVideoUploadSession $session): JsonResponse
    {
        return response()->json(['data' => app(CustomerReturnVideoUploadService::class)->status($session, $request->user())]);
    }

    public function uploadVideoChunk(Request $request, CustomerReturnVideoUploadSession $session, int $index): JsonResponse
    {
        $data = $request->validate([
            'checksum' => ['required', 'regex:/^[a-f0-9]{32}$/'],
            'chunk' => ['required', 'file', 'max:5120'],
        ]);
        $status = app(CustomerReturnVideoUploadService::class)->chunk($session, $request->user(), $index,
            $data['checksum'], $request->file('chunk'));

        return response()->json(['data' => $status]);
    }

    public function finalizeVideo(Request $request, CustomerReturnVideoUploadSession $session): JsonResponse
    {
        $evidence = app(CustomerReturnVideoUploadService::class)->finalize($session, $request->user());

        return response()->json(['data' => $this->present($evidence), 'message' => 'Video evidence saved on the server.']);
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
        $label = $evidence->label;
        $path = $label?->path ?? $evidence->original_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), [
            'Content-Type' => $label?->mime_type ?? $evidence->mime_type, 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function labeled(Request $request, CustomerReturnEvidence $evidence): BinaryFileResponse
    {
        app(CustomerReturnEvidenceAuthorization::class)->authorizeView($request->user(), $evidence->finding->item->customerReturn);
        $label = $evidence->label;
        abort_unless($label && Storage::disk('local')->exists($label->path), 404);

        return response()->download(Storage::disk('local')->path($label->path),
            'TPZ-'.$evidence->finding->item->customerReturn->reference.'-'.$evidence->id.'.'.($evidence->media_type === 'video' ? 'mp4' : 'jpg'),
            ['Content-Type' => $label->mime_type, 'X-Content-Type-Options' => 'nosniff']);
    }

    private function present(CustomerReturnEvidence $evidence, ?int $videoPart = null): array
    {
        if ($evidence->media_type === 'video' && $videoPart === null) {
            $videoPart = CustomerReturnEvidence::query()->where('media_type', 'video')
                ->where('id', '<', $evidence->id)
                ->whereHas('finding.item', fn ($query) => $query->where('customer_return_id', $evidence->finding->item->customer_return_id))
                ->count() + 1;
        }

        return [
            'id' => $evidence->id, 'media_type' => $evidence->media_type,
            'mime_type' => $evidence->mime_type, 'original_name' => $evidence->original_name,
            'byte_size' => $evidence->byte_size, 'duration_seconds' => $evidence->duration_seconds,
            'video_part' => $videoPart,
            'uploaded_at' => $evidence->uploaded_at?->toIso8601String(),
            'captured_at_device' => $evidence->captured_at_device?->toIso8601String(),
            'capture_timezone' => $evidence->capture_timezone,
            'label_status' => $evidence->label !== null ? 'ready' : 'pending',
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

    private function qcSummary(CustomerReturnItem $item): array
    {
        $item->loadMissing('inspections', 'removalItems.inspections');
        $inspections = $item->inspections->concat($item->removalItems->flatMap->inspections)->unique('id');
        $sellable = $inspections->filter(fn ($inspection) => $inspection->result === CustomerReturnInspectionResult::Sellable)->sum('quantity');
        $damaged = $inspections->filter(fn ($inspection) => $inspection->result === CustomerReturnInspectionResult::Damaged)->sum('quantity');

        return [
            'result' => $sellable > 0 && $damaged > 0 ? 'mixed' : ($sellable > 0 ? 'sellable' : ($damaged > 0 ? 'damaged' : null)),
            'inspected_quantity' => $sellable + $damaged,
            'sellable_quantity' => $sellable,
            'damaged_quantity' => $damaged,
        ];
    }
}
