<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ProductCondition;
use App\Enums\QuotationDocumentType;
use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Models\Product;
use App\Models\Quotation;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Orders\OrderFulfillmentLocationService;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationPdfService;
use App\Services\Quotations\QuotationService;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class QuotationController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $auth = app(QuotationAuthorization::class);
        $auth->authorize($request->user(), QuotationPermission::View);
        $query = $auth->scope(Quotation::query(), $request->user())->with('salesperson:id,name')
            ->orderByDesc('quotation_date')->orderByDesc('id');
        $response = $this->page($request, $query, ['reference', 'customer_name', 'customer_company', 'external_reference'],
            fn (Quotation $quotation): array => $this->present($request, $quotation));
        $response->setData([...$response->getData(true),
            'can_create' => $auth->allows($request->user(), QuotationPermission::Create)]);

        return $response;
    }

    public function show(Request $request, int $quotation): JsonResponse
    {
        $quotation = Quotation::query()->with(['items', 'warehouse:id,name', 'salesperson:id,name', 'order:id,reference', 'taxInvoice:id,invoice_number'])->findOrFail($quotation);
        app(QuotationAuthorization::class)->authorize($request->user(), QuotationPermission::View, $quotation);

        return response()->json(['data' => $this->present($request, $quotation, true)]);
    }

    public function options(Request $request): JsonResponse
    {
        $auth = app(QuotationAuthorization::class);
        $user = $request->user();
        $input = $request->validate(['quotation_id' => 'nullable|integer', 'q' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        if (isset($input['quotation_id'])) {
            $auth->authorize($user, QuotationPermission::Update, Quotation::query()->findOrFail($input['quotation_id']));
        } else {
            $auth->authorize($user, QuotationPermission::Create);
        }
        $products = Product::query()->products()->active()->with(['brandRelation', 'categoryRelation']);
        app(ResponsibilityProductScopeService::class)->apply($products->getQuery(), 'products.id', $user);
        if (filled($input['q'] ?? null)) {
            $like = '%'.trim($input['q']).'%';
            $products->where(fn ($query) => $query->where('sku', 'like', $like)->orWhere('name', 'like', $like)->orWhere('model', 'like', $like));
        }

        return response()->json(['data' => [
            'warehouses' => collect(app(OrderFulfillmentLocationService::class)->options(null))
                ->map(fn ($name, $id): array => ['id' => (int) $id, 'name' => $name])->values(),
            'products' => $products->orderBy('name')->paginate(25)->through(fn (Product $product): array => [
                'id' => $product->id, 'name' => $product->name, 'sku' => $product->sku,
                'brand' => $product->displayBrandName(), 'model' => $product->model,
            ]),
            'document_types' => collect(QuotationDocumentType::cases())->map(fn ($type): array => ['value' => $type->value, 'label' => $type->label()])->values(),
            'conditions' => collect(ProductCondition::cases())->map(fn ($condition): array => ['value' => $condition->value, 'label' => $condition->label()])->values(),
            'supports_manual_sourcing' => false,
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->assertProductsVisible($request);
        if ($existing = Quotation::query()->where('idempotency_key', $request->input('idempotency_key'))->first()) {
            abort_unless($existing->created_by_user_id === $request->user()->id, 403);

            return $this->show($request, $existing->id);
        }
        $quotation = app(QuotationService::class)->create($request->all(), $request->user());

        return $this->show($request, $quotation->id);
    }

    public function update(Request $request, int $quotation): JsonResponse
    {
        $quotation = Quotation::query()->findOrFail($quotation);
        app(QuotationAuthorization::class)->authorize($request->user(), QuotationPermission::Update, $quotation);
        $this->assertProductsVisible($request);
        app(QuotationService::class)->updateDraft($quotation, $request->all(), $request->user());

        return $this->show($request, $quotation->id);
    }

    public function act(Request $request, int $quotation, string $action): JsonResponse
    {
        $quotation = Quotation::query()->findOrFail($quotation);
        $user = $request->user();
        match ($action) {
            'send' => app(QuotationService::class)->transition($quotation, QuotationStatus::Sent, $user),
            'accept' => app(QuotationService::class)->transition($quotation, QuotationStatus::Accepted, $user),
            'reject' => app(QuotationService::class)->transition($quotation, QuotationStatus::Rejected, $user,
                $request->validate(['reason' => 'required|string|max:2000'])['reason']),
            'cancel' => app(QuotationService::class)->transition($quotation, QuotationStatus::Cancelled, $user,
                $request->validate(['reason' => 'required|string|max:2000'])['reason']),
            'convert-order' => app(QuotationConversionService::class)->toOrder($quotation,
                (int) $request->validate(['warehouse_id' => 'required|integer', 'customer_phone' => 'nullable|string|max:40', 'idempotency_key' => 'required|uuid'])['warehouse_id'],
                $user, $request->input('idempotency_key'), $request->input('customer_phone')),
            'convert-invoice' => app(QuotationConversionService::class)->toInvoice($quotation, $user,
                $request->validate(['idempotency_key' => 'required|uuid'])['idempotency_key']),
            default => abort(404),
        };

        return $this->show($request, $quotation->id);
    }

    public function pdf(Request $request, int $quotation): Response
    {
        $quotation = Quotation::query()->findOrFail($quotation);
        app(QuotationAuthorization::class)->authorize($request->user(), QuotationPermission::Export, $quotation);

        return app(QuotationPdfService::class)->render($quotation)
            ->download(str($quotation->reference)->slug('-').'.pdf');
    }

    private function present(Request $request, Quotation $quotation, bool $detail = false): array
    {
        $data = [
            'id' => $quotation->id,
            'title' => $quotation->reference,
            'subtitle' => $quotation->customer_name,
            'meta' => $quotation->quotation_date?->format('Y-m-d').' · AED '.$quotation->grand_total,
            'status' => $quotation->effectiveStatus()->value,
        ];
        if (! $detail) {
            return $data;
        }
        $auth = app(QuotationAuthorization::class);
        $user = $request->user();
        $fields = [
            'document_type' => $quotation->document_type->value,
            'quotation_date' => $quotation->quotation_date?->format('Y-m-d'),
            'valid_until' => $quotation->valid_until?->format('Y-m-d'),
            'customer_name' => $quotation->customer_name,
            'customer_company' => $quotation->customer_company,
            'customer_phone' => $quotation->customer_phone,
            'customer_email' => $quotation->customer_email,
            'customer_address' => $quotation->customer_address,
            'customer_trn' => $quotation->customer_trn,
            'external_reference' => $quotation->external_reference,
            'warehouse' => $quotation->warehouse?->name,
            'salesperson' => $quotation->salesperson?->name,
            'subtotal_excluding_vat' => $quotation->subtotal_excluding_vat,
            'discount_total' => $quotation->discount_total,
            'vat_amount' => $quotation->vat_amount,
            'grand_total' => $quotation->grand_total,
            'notes' => $quotation->notes,
        ];
        $actions = [];
        $status = $quotation->effectiveStatus();
        if ($status === QuotationStatus::Draft && $auth->allows($user, QuotationPermission::Update, $quotation)) {
            $actions[] = $this->action('edit', 'Edit draft quotation');
        }
        if ($status === QuotationStatus::Draft && $auth->allows($user, QuotationPermission::Send, $quotation)) {
            $actions[] = $this->action('send', 'Mark sent', confirmationMessage: 'Mark this quotation as sent? Its commercial content will become immutable.');
        }
        if ($status === QuotationStatus::Sent && $auth->allows($user, QuotationPermission::Accept, $quotation)) {
            $actions[] = $this->action('accept', 'Accept quotation');
        }
        if ($status === QuotationStatus::Sent && $auth->allows($user, QuotationPermission::Reject, $quotation)) {
            $actions[] = $this->action('reject', 'Reject quotation', [$this->field('reason', 'Reason', 'multiline', true)]);
        }
        if (in_array($status, [QuotationStatus::Draft, QuotationStatus::Sent, QuotationStatus::Accepted], true)
            && $auth->allows($user, QuotationPermission::Cancel, $quotation)) {
            $actions[] = $this->action('cancel', 'Cancel quotation', [$this->field('reason', 'Reason', 'multiline', true)]);
        }
        if ($status === QuotationStatus::Accepted && $auth->allows($user, QuotationPermission::ConvertOrder, $quotation)) {
            $actions[] = $this->action('convert-order', 'Convert to order', [
                $this->field('warehouse_id', 'Warehouse', 'select', true, options: collect(app(OrderFulfillmentLocationService::class)->options(null))
                    ->map(fn ($name, $id): array => ['value' => (int) $id, 'label' => $name])->values()->all()),
                $this->field('customer_phone', 'Customer phone', 'text', false, $quotation->customer_phone),
            ]);
        }
        if ($status === QuotationStatus::Accepted && $auth->allows($user, QuotationPermission::ConvertInvoice, $quotation)) {
            $actions[] = $this->action('convert-invoice', 'Convert to invoice');
        }
        $related = collect([
            $quotation->order ? ['label' => $quotation->order->reference, 'target' => ['module' => 'orders', 'id' => $quotation->order->id]] : null,
            $quotation->taxInvoice ? ['label' => $quotation->taxInvoice->invoice_number, 'target' => ['module' => 'invoices', 'id' => $quotation->taxInvoice->id]] : null,
        ])->filter()->values()->all();

        return [...$data, 'fields' => $fields, 'field_schema' => $this->readFields($fields, [
            'subtotal_excluding_vat' => 'currency', 'discount_total' => 'currency', 'vat_amount' => 'currency',
            'grand_total' => 'currency', 'customer_address' => 'multiline', 'notes' => 'multiline',
        ]), 'items' => $quotation->items->map(fn ($item): array => [
            'id' => $item->id, 'product_id' => $item->product_id, 'source_type' => $item->source_type->value,
            'sku' => $item->sku, 'product_name' => $item->product_name, 'description' => $item->description,
            'quantity' => $item->quantity, 'unit_price_including_vat' => $item->unit_price_including_vat,
            'discount_amount' => $item->discount_amount, 'vat_rate' => $item->vat_rate,
            'total_including_vat' => $item->total_including_vat,
        ]), 'actions' => $actions, 'related' => $related, 'can_download_pdf' => $auth->allows($user, QuotationPermission::Export, $quotation),
            'edit_values' => $status === QuotationStatus::Draft && $auth->allows($user, QuotationPermission::Update, $quotation)
                ? [
                    'warehouse_id' => $quotation->warehouse_id, 'document_type' => $quotation->document_type->value,
                    'quotation_date' => $quotation->quotation_date?->format('Y-m-d'), 'valid_until' => $quotation->valid_until?->format('Y-m-d'),
                    ...$quotation->only(['customer_name', 'customer_company', 'customer_phone', 'customer_email', 'customer_address', 'customer_trn', 'external_reference', 'notes']),
                ] : null,
            'edit_items' => $status === QuotationStatus::Draft ? $quotation->items->map(fn ($item): array => [
                'quotation_item_id' => $item->id, 'source_type' => $item->source_type->value, 'product_id' => $item->product_id,
                'title' => $item->product_name, 'description' => $item->description, 'quantity' => $item->quantity,
                'unit_price_including_vat' => $item->unit_price_including_vat, 'discount_amount' => $item->discount_amount,
                'vat_rate' => $item->vat_rate,
            ]) : null];
    }

    private function assertProductsVisible(Request $request): void
    {
        $ids = collect($request->input('items', []))->where('source_type', 'existing_product')
            ->pluck('product_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        foreach ($ids as $id) {
            if (! app(ResponsibilityProductScopeService::class)->canAccessProduct($request->user(), $id)) {
                throw ValidationException::withMessages(['items' => 'One or more Products are outside your active Responsibility scope.']);
            }
        }
    }
}
