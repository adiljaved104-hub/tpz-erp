<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\InvoicePermission;
use App\Enums\ProductTitleMode;
use App\Enums\TaxInvoiceTermsProfile;
use App\Models\TaxInvoice;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceDocumentService;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $authorization = app(InvoiceAuthorization::class);
        $authorization->authorize($request->user(), InvoicePermission::View);

        $response = $this->page(
            $request,
            $authorization->scope(TaxInvoice::query(), $request->user())
                ->select(['id', 'invoice_number', 'order_reference', 'customer_name', 'invoice_date', 'status', 'created_by_user_id'])
                ->orderByDesc('invoice_date')->orderByDesc('id'),
            ['invoice_number', 'order_reference', 'customer_name'],
            fn (TaxInvoice $invoice): array => $this->present($request, $invoice),
        );
        $response->setData([...$response->getData(true),
            'can_create' => $authorization->allows($request->user(), InvoicePermission::Create)]);

        return $response;
    }

    public function show(Request $request, int $invoice): JsonResponse
    {
        $authorization = app(InvoiceAuthorization::class);
        $invoice = TaxInvoice::query()->with('items')->findOrFail($invoice);
        $authorization->authorize($request->user(), InvoicePermission::View, $invoice);

        return response()->json(['data' => $this->present($request, $invoice, true)]);
    }

    public function pdf(Request $request, int $invoice): Response
    {
        $invoice = TaxInvoice::query()->findOrFail($invoice);

        app(InvoiceAuthorization::class)->authorize(
            $request->user(),
            InvoicePermission::DownloadPdf,
            $invoice,
        );

        $documents = app(TaxInvoiceDocumentService::class);

        return $documents->pdf($invoice)
            ->download($documents->filename($invoice));
    }

    public function options(Request $request): JsonResponse
    {
        app(InvoiceAuthorization::class)->authorize($request->user(), InvoicePermission::Create);
        $input = $request->validate([
            'q' => 'nullable|string|max:100',
            'order_id' => 'nullable|integer',
            'title_mode' => ['nullable', Rule::enum(ProductTitleMode::class)],
        ]);
        $imports = app(TaxInvoiceOrderImportService::class);

        return response()->json(['data' => [
            'orders' => collect($imports->options($request->user(), trim((string) ($input['q'] ?? ''))))
                ->map(fn ($label, $id): array => ['id' => (int) $id, 'name' => $label])->values(),
            'prefill' => isset($input['order_id'])
                ? $imports->prefill($request->user(), (int) $input['order_id'], $input['title_mode'] ?? ProductTitleMode::Auto)
                : null,
            'title_modes' => collect(ProductTitleMode::cases())->map(fn ($mode): array => ['value' => $mode->value, 'label' => str($mode->value)->headline()->toString()])->values(),
            'terms_profiles' => collect(TaxInvoiceTermsProfile::cases())->map(fn ($profile): array => ['value' => $profile->value, 'label' => str($profile->value)->headline()->toString()])->values(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        app(InvoiceAuthorization::class)->authorize($request->user(), InvoicePermission::Create);
        if ($existing = TaxInvoice::query()->where('idempotency_key', $request->input('idempotency_key'))->first()) {
            abort_unless($existing->created_by_user_id === $request->user()->id, 403);

            return $this->show($request, $existing->id);
        }
        $invoice = app(TaxInvoiceService::class)->create($request->all(), $request->user());

        return $this->show($request, $invoice->id);
    }

    public function act(Request $request, int $invoice, string $action): JsonResponse
    {
        $invoice = TaxInvoice::query()->findOrFail($invoice);
        match ($action) {
            'edit-customer' => app(TaxInvoiceService::class)->updateCustomerDetails($invoice, $request->all(), $request->user()),
            'void' => app(TaxInvoiceService::class)->void($invoice,
                $request->validate(['reason' => 'required|string|min:5|max:2000'])['reason'], $request->user()),
            default => abort(404),
        };

        return $this->show($request, $invoice->id);
    }

    private function present(Request $request, TaxInvoice $invoice, bool $detail = false): array
    {
        $data = [
            'id' => $invoice->id,
            'title' => $invoice->invoice_number,
            'subtitle' => $invoice->customer_name,
            'meta' => $invoice->invoice_date?->format('Y-m-d'),
            'status' => $invoice->status,
        ];

        if (! $detail) {
            return $data;
        }

        $authorization = app(InvoiceAuthorization::class);

        $fields = [
            'invoice_number' => $invoice->invoice_number,
            'order_reference' => $invoice->order_reference,
            'customer_name' => $invoice->customer_name,
            'customer_phone' => $invoice->customer_phone,
            'customer_address' => $invoice->customer_address,
            'invoice_date' => $invoice->invoice_date?->format('Y-m-d'),
            'issued_at' => $invoice->issued_at?->format('Y-m-d H:i'),
            'voided_at' => $invoice->voided_at?->format('Y-m-d H:i'),
            'void_reason' => $invoice->void_reason,
        ];

        $financial = $authorization->allows(
            $request->user(),
            InvoicePermission::ViewAll,
            $invoice,
        );

        if ($financial) {
            $fields += [
                'customer_trn' => $invoice->customer_trn,
                'vat_rate' => $invoice->vat_rate,
                'subtotal_excluding_vat' => $invoice->subtotal_excluding_vat,
                'vat_amount' => $invoice->vat_amount,
                'grand_total' => $invoice->grand_total,
            ];
        }

        $actions = [];
        if ($authorization->allows($request->user(), InvoicePermission::EditCustomerDetails, $invoice)) {
            $actions[] = $this->action('edit-customer', 'Correct customer details', [
                $this->field('customer_name', 'Customer name', 'text', true, $invoice->customer_name),
                $this->field('customer_phone', 'Customer phone', 'text', false, $invoice->customer_phone),
                $this->field('customer_address', 'Customer address', 'multiline', true, $invoice->customer_address),
                $this->field('customer_trn', 'Customer TRN', 'text', false, $invoice->customer_trn),
                $this->field('amendment_reason', 'Correction reason', 'multiline', true),
            ]);
        }
        if ($invoice->status === 'issued' && $authorization->allows($request->user(), InvoicePermission::Void, $invoice)) {
            $actions[] = $this->action('void', 'Void invoice', [$this->field('reason', 'Void reason', 'multiline', true)],
                confirmationMessage: 'Voiding is final and preserves the immutable invoice snapshot.');
        }

        return [
            ...$data,
            'fields' => $fields,
            'items' => $invoice->items->map(fn ($item): array => [
                'description' => $item->description,
                'quantity' => $item->quantity,
                ...($financial ? [
                    'unit_price_including_vat' => $item->unit_price_including_vat,
                    'subtotal_excluding_vat' => $item->subtotal_excluding_vat,
                    'vat_amount' => $item->vat_amount,
                    'total_including_vat' => $item->total_including_vat,
                ] : []),
            ]),
            'can_download_pdf' => $authorization->allows(
                $request->user(),
                InvoicePermission::DownloadPdf,
                $invoice,
            ),
            'actions' => $actions,
        ];
    }
}
