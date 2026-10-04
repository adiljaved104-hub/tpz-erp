<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\PurchasePermission;
use App\Models\PurchaseReceipt;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReceiptController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $authorization = app(PurchaseAuthorization::class);
        $authorization->authorize($request->user(), PurchasePermission::ViewReceipts);
        $input = $request->validate([
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);
        $query = PurchaseReceipt::query()
            ->join('purchases', 'purchases.id', '=', 'purchase_receipts.purchase_id')
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
            ->select('purchase_receipts.*')
            ->with(['purchase.supplier:id,name', 'warehouse:id,name', 'receivedBy:id,name'])
            ->when(filled($input['q'] ?? null), function (Builder $query) use ($input): void {
                $term = '%'.$input['q'].'%';
                $query->where(fn ($match) => $match
                    ->where('purchase_receipts.reference', 'like', $term)
                    ->orWhere('purchase_receipts.supplier_delivery_note', 'like', $term)
                    ->orWhere('purchases.reference', 'like', $term)
                    ->orWhere('suppliers.name', 'like', $term));
            })
            ->orderByDesc('purchase_receipts.received_at')
            ->orderByDesc('purchase_receipts.id');

        return response()->json($query->paginate($input['per_page'] ?? 25)
            ->through(fn (PurchaseReceipt $receipt) => $this->present($request, $receipt)));
    }

    public function show(Request $request, int $receipt): JsonResponse
    {
        $record = PurchaseReceipt::query()->with([
            'purchase.supplier:id,name',
            'warehouse:id,name',
            'receivedBy:id,name',
            'items.product:id,sku,name',
        ])->findOrFail($receipt);
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::ViewReceipts, $record->purchase);

        return response()->json(['data' => $this->present($request, $record, true)]);
    }

    private function present(Request $request, PurchaseReceipt $receipt, bool $detail = false): array
    {
        $data = [
            'id' => $receipt->id,
            'title' => $receipt->reference,
            'subtitle' => $receipt->purchase?->reference,
            'meta' => $receipt->received_at?->format('Y-m-d H:i'),
            'status' => 'received',
        ];
        if (! $detail) {
            return $data;
        }
        $financial = app(PurchaseAuthorization::class)->allows($request->user(), PurchasePermission::ViewFinancials, $receipt->purchase);
        $items = $receipt->items->map(fn ($item) => [
            'id' => $item->id,
            'product_id' => $item->product_id,
            'product' => $item->product?->name,
            'sku' => $item->product?->sku,
            'received_quantity' => $item->quantity_received,
            'accepted_quantity' => $item->accepted_quantity,
            'damaged_quantity' => $item->damaged_quantity,
            'rejected_quantity' => $item->rejected_quantity,
            ...($financial ? ['inventory_unit_cost' => $item->inventory_unit_cost] : []),
        ]);

        return [...$data,
            'fields' => [
                'purchase_reference' => $receipt->purchase?->reference,
                'supplier' => $receipt->purchase?->supplier?->name,
                'warehouse' => $receipt->warehouse?->name,
                'received_at' => $receipt->received_at?->toIso8601String(),
                'received_by' => $receipt->receivedBy?->name,
                'supplier_delivery_note' => $receipt->supplier_delivery_note,
                'notes' => $receipt->notes,
                'received_quantity' => $items->sum('received_quantity'),
                'accepted_quantity' => $items->sum('accepted_quantity'),
                'damaged_quantity' => $items->sum('damaged_quantity'),
                'rejected_quantity' => $items->sum('rejected_quantity'),
            ],
            'items' => $items,
            'actions' => [],
        ];
    }
}
