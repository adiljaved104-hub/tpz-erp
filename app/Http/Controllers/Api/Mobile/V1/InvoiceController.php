<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class InvoiceController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $authorization = app(InvoiceAuthorization::class);
        $authorization->authorize($request->user(), InvoicePermission::View);

        return $this->page(
            $request,
            $authorization->scope(TaxInvoice::query(), $request->user())
                ->select(['id', 'invoice_number', 'order_reference', 'customer_name', 'invoice_date', 'status', 'created_by_user_id'])
                ->orderByDesc('invoice_date')->orderByDesc('id'),
            ['invoice_number', 'order_reference', 'customer_name'],
            fn (TaxInvoice $invoice): array => $this->present($request, $invoice),
        );
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
            'actions' => [],
        ];
    }
}