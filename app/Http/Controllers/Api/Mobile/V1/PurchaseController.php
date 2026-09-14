<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CancelPurchase;
use App\Actions\Purchases\ClosePurchase;
use App\Actions\Purchases\CreatePurchase;
use App\Actions\Purchases\ReceivePurchase;
use App\Actions\Purchases\UpdateDraftPurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CancelPurchaseData;
use App\DTOs\Purchases\ClosePurchaseData;
use App\DTOs\Purchases\CreatePurchaseData;
use App\DTOs\Purchases\PurchaseItemData;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\DTOs\Purchases\UpdatePurchaseData;
use App\Enums\PurchasePermission;
use App\Enums\PurchaseStatus;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReceipt;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Purchases\PurchaseReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends MobileController
{
    private function readable(Request $request, int $id): Purchase
    {
        $purchase = Purchase::query()->with(['supplier:id,name', 'warehouse:id,name', 'items.product:id,name,sku', 'receipts.items'])->findOrFail($id);
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::View, $purchase);

        return $purchase;
    }

    public function index(Request $request): JsonResponse
    {
        $response = $this->page($request, app(PurchaseReadService::class)->purchases($request->user())
            ->with(['supplier:id,name'])->orderByDesc('purchase_date')->orderByDesc('id'),
            ['reference', 'supplier_invoice_number'], fn ($p) => $this->present($request, $p));
        $response->setData([...$response->getData(true), 'can_create' => app(PurchaseAuthorization::class)->allows($request->user(), PurchasePermission::Create)]);

        return $response;
    }

    public function show(Request $request, int $purchase): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->readable($request, $purchase), true)]);
    }

    private function present(Request $request, Purchase $p, bool $detail = false): array
    {
        $auth = app(PurchaseAuthorization::class);
        $user = $request->user();
        $financial = $auth->allows($user, PurchasePermission::ViewFinancials, $p);
        $data = ['id' => $p->id, 'title' => $p->reference, 'subtitle' => $p->supplier?->name ?? 'No supplier',
            'meta' => $p->purchase_date?->format('Y-m-d'), 'status' => $p->status->value];
        if (! $detail) {
            return $data;
        }

        $actions = [];
        if ($p->status === PurchaseStatus::Draft && $financial && $auth->allows($user, PurchasePermission::UpdateDraft, $p)) {
            $actions[] = $this->action('edit', 'Edit draft');
        }
        if ($p->status === PurchaseStatus::Draft && $auth->allows($user, PurchasePermission::Approve, $p)) {
            $actions[] = $this->action('approve', 'Approve purchase', [$this->field('reason', 'Approval reason', 'multiline', true)]);
        }
        if ($p->status->isOpenForReceiving() && $auth->allows($user, PurchasePermission::Receive, $p)) {
            $actions[] = $this->action('receive', 'Receive goods');
        }
        if (in_array($p->status, [PurchaseStatus::Draft, PurchaseStatus::Approved], true)
            && $auth->allows($user, PurchasePermission::Cancel, $p) && ! $p->hasReceipts()) {
            $actions[] = $this->action('cancel', 'Cancel purchase', [$this->field('reason', 'Cancellation reason', 'multiline', true)]);
        }
        if (in_array($p->status, [PurchaseStatus::Approved, PurchaseStatus::PartiallyReceived, PurchaseStatus::FullyReceived], true)
            && $auth->allows($user, PurchasePermission::Close, $p) && $p->hasReceipts()) {
            $actions[] = $this->action('close', 'Close purchase', [$this->field('reason', 'Closure reason', 'multiline', true)]);
        }
        $fields = ['supplier' => $p->supplier?->name, 'warehouse' => $p->warehouse?->name,
            'purchase_date' => $p->purchase_date?->format('Y-m-d'), 'expected_delivery_date' => $p->expected_delivery_date?->format('Y-m-d'),
            'supplier_invoice_number' => $p->supplier_invoice_number, 'notes' => $p->notes];
        if ($financial) {
            $fields['grand_total'] = $p->grand_total;
        }
        $receipts = $auth->allows($user, PurchasePermission::ViewReceipts, $p)
            ? $p->receipts->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference,
                'received_at' => $r->received_at?->format('Y-m-d H:i'),
                'items' => $r->items->map(fn ($i) => ['product_id' => $i->product_id, 'accepted_quantity' => $i->accepted_quantity,
                    'damaged_quantity' => $i->damaged_quantity, 'rejected_quantity' => $i->rejected_quantity])])->all()
            : [];

        $editValues = $auth->allows($user, PurchasePermission::UpdateDraft, $p) && $financial ? [
            'supplier_id' => $p->supplier_id, 'warehouse_id' => $p->warehouse_id, 'purchase_date' => $p->purchase_date?->format('Y-m-d'),
            'supplier_invoice_number' => $p->supplier_invoice_number, 'supplier_invoice_date' => $p->supplier_invoice_date?->format('Y-m-d'),
            'external_accounting_reference' => $p->external_accounting_reference, 'shipping_total' => $p->shipping_total,
            'shipping_vat_rate' => $p->shipping_vat_rate, 'other_charges_total' => $p->other_charges_total,
            'other_charges_vat_rate' => $p->other_charges_vat_rate,
            'expected_delivery_date' => $p->expected_delivery_date?->format('Y-m-d'), 'notes' => $p->notes,
        ] : null;

        return [...$data, 'fields' => $fields, 'edit_values' => $editValues, 'edit_items' => $editValues ? $p->items->map(fn ($i) => [
            'product_id' => $i->product_id, 'title' => $i->product?->name, 'ordered_quantity' => $i->ordered_quantity,
            'unit_cost' => $i->unit_cost, 'line_discount_total' => $i->line_discount_total, 'vat_rate' => $i->vat_rate,
        ]) : null, 'items' => $p->items->map(fn ($i) => [
            'id' => $i->id, 'product_id' => $i->product_id, 'title' => $i->product?->name, 'sku' => $i->product?->sku,
            'quantity' => $i->ordered_quantity, 'received_quantity' => $i->received_quantity,
            'remaining_quantity' => $i->outstandingQuantity(),
            ...($financial ? ['unit_cost' => $i->unit_cost, 'line_total' => $i->line_total] : []),
        ]), 'receipts' => $receipts, 'actions' => $actions];
    }

    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $input = $request->validate(['purchase_id' => 'nullable|integer', 'warehouse_id' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $auth = app(PurchaseAuthorization::class);
        if (isset($input['purchase_id'])) {
            $auth->authorize($user, PurchasePermission::UpdateDraft, Purchase::query()->findOrFail($input['purchase_id']));
        } else {
            $auth->authorize($user, PurchasePermission::Create);
        }
        $products = Product::query()->active();
        if (isset($input['warehouse_id'])) {
            $warehouse = Warehouse::query()->where('status', true)->findOrFail($input['warehouse_id']);
            app(OrderResponsibilityScopeService::class)->applyProducts($products, $user, $warehouse->marketplace_platform_id, $warehouse->id);
        } else {
            $products->whereRaw('1=0');
        }
        if (filled($input['q'] ?? null)) {
            $term = '%'.$input['q'].'%';
            $products->where(fn ($query) => $query->where('name', 'like', $term)->orWhere('sku', 'like', $term));
        }

        return response()->json(['data' => [
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(['id', 'name']),
            'warehouses' => Warehouse::query()->where('status', true)->orderBy('name')->get(['id', 'name']),
            'products' => $products->orderBy('name')->paginate(25)->through(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku]),
        ]]);
    }

    private function document(Request $request): array
    {
        $d = $request->validate([
            'supplier_id' => 'nullable|integer', 'warehouse_id' => 'required|integer', 'purchase_date' => 'required|date_format:Y-m-d',
            'supplier_invoice_number' => 'nullable|string|max:255', 'supplier_invoice_date' => 'nullable|date_format:Y-m-d',
            'expected_delivery_date' => 'nullable|date_format:Y-m-d', 'external_accounting_reference' => 'nullable|string|max:255',
            'shipping_total' => 'nullable|numeric|min:0', 'shipping_vat_rate' => 'nullable|numeric|min:0',
            'other_charges_total' => 'nullable|numeric|min:0', 'other_charges_vat_rate' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000', 'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|integer', 'items.*.ordered_quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0', 'items.*.line_discount_total' => 'nullable|numeric|min:0',
            'items.*.vat_rate' => 'nullable|numeric|min:0', 'items.*.notes' => 'nullable|string|max:2000',
        ]);
        $items = array_map(fn ($i) => new PurchaseItemData($i['product_id'], $i['ordered_quantity'], (string) $i['unit_cost'],
            (string) ($i['line_discount_total'] ?? '0'), (string) ($i['vat_rate'] ?? '0'), $i['notes'] ?? null), $d['items']);

        return [$d, $items];
    }

    public function store(Request $request): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::Create);
        [$d, $items] = $this->document($request);
        $p = app(CreatePurchase::class)->handle(new CreatePurchaseData($d['supplier_id'] ?? null, $d['warehouse_id'], $d['purchase_date'], $items,
            $d['supplier_invoice_number'] ?? null, $d['supplier_invoice_date'] ?? null, $d['expected_delivery_date'] ?? null,
            $d['external_accounting_reference'] ?? null, (string) ($d['shipping_total'] ?? '0'), (string) ($d['shipping_vat_rate'] ?? '0'),
            (string) ($d['other_charges_total'] ?? '0'), (string) ($d['other_charges_vat_rate'] ?? '0'), $d['notes'] ?? null,
            $request->user()->employee?->id), $request->user());

        return $this->show($request, $p->id);
    }

    public function update(Request $request, int $purchase): JsonResponse
    {
        $p = $this->readable($request, $purchase);
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::UpdateDraft, $p);
        [$d, $items] = $this->document($request);
        app(UpdateDraftPurchase::class)->handle($p, new UpdatePurchaseData($d['supplier_id'] ?? null, $d['warehouse_id'], $d['purchase_date'], $items,
            $d['supplier_invoice_number'] ?? null, $d['supplier_invoice_date'] ?? null, $d['expected_delivery_date'] ?? null,
            $d['external_accounting_reference'] ?? null, (string) ($d['shipping_total'] ?? '0'), (string) ($d['shipping_vat_rate'] ?? '0'),
            (string) ($d['other_charges_total'] ?? '0'), (string) ($d['other_charges_vat_rate'] ?? '0'), $d['notes'] ?? null,
            $p->handled_by_employee_id), $request->user());

        return $this->show($request, $p->id);
    }

    public function act(Request $request, int $purchase, string $action): JsonResponse
    {
        $p = $this->readable($request, $purchase);
        $user = $request->user();
        if ($action === 'receive') {
            app(PurchaseAuthorization::class)->authorize($user, PurchasePermission::Receive, $p);
            $d = $request->validate(['received_at' => 'required|date', 'idempotency_key' => 'required|uuid',
                'supplier_delivery_note' => 'nullable|string|max:255', 'notes' => 'nullable|string|max:2000',
                'items' => 'required|array|min:1', 'items.*.purchase_item_id' => 'required|integer',
                'items.*.accepted_quantity' => 'required|integer|min:0', 'items.*.damaged_quantity' => 'required|integer|min:0',
                'items.*.rejected_quantity' => 'required|integer|min:0']);
            if ($retry = PurchaseReceipt::query()->where('idempotency_key', $d['idempotency_key'])->first()) {
                abort_unless($retry->purchase_id === $p->id && $retry->received_by_user_id === $user->id, 403);

                return $this->show($request, $p->id);
            }
            $items = array_map(fn ($i) => new PurchaseReceiptItemData($i['purchase_item_id'], $i['accepted_quantity'],
                $i['damaged_quantity'], $i['rejected_quantity']), $d['items']);
            app(ReceivePurchase::class)->handle($p, new ReceivePurchaseData($items, $d['received_at'], $d['idempotency_key'],
                $d['supplier_delivery_note'] ?? null, $d['notes'] ?? null), $user);
        } else {
            $d = $request->validate(['reason' => 'required|string|max:2000']);
            match ($action) {
                'approve' => app(ApprovePurchase::class)->handle($p, new ApprovePurchaseData($d['reason'], true), $user),
                'cancel' => app(CancelPurchase::class)->handle($p, new CancelPurchaseData($d['reason']), $user),
                'close' => app(ClosePurchase::class)->handle($p, new ClosePurchaseData($d['reason'], true), $user),
                default => abort(404),
            };
        }

        return $this->show($request, $p->id);
    }
}
