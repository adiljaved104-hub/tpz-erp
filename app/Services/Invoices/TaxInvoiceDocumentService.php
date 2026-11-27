<?php

namespace App\Services\Invoices;

use App\Models\OrderAmendmentLine;
use App\Models\TaxInvoice;
use App\Support\ArabicPdfText;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DompdfDocument;
use Illuminate\Support\Facades\Storage;

class TaxInvoiceDocumentService
{
    public function __construct(
        private readonly InvoiceVerificationService $verification,
        private readonly ArabicPdfText $arabic,
    ) {}

    public function pdf(TaxInvoice $invoice): DompdfDocument
    {
        return Pdf::loadView('invoices.tax-invoice', [
            ...$this->viewData($invoice),
            'documentMode' => 'pdf',
        ])->setPaper('a4');
    }

    /** @return array<string, mixed> */
    public function printViewData(TaxInvoice $invoice): array
    {
        return [
            ...$this->viewData($invoice),
            'documentMode' => 'print',
        ];
    }

    public function filename(TaxInvoice $invoice): string
    {
        $reference = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($invoice->invoice_number));
        $reference = trim((string) $reference, '-_');

        return ($reference !== '' ? $reference : 'tax-invoice-'.$invoice->getKey()).'.pdf';
    }

    /** @return array<string, mixed> */
    private function viewData(TaxInvoice $invoice): array
    {
        $invoice->loadMissing(['items', 'createdBy']);

        return [
            'invoice' => $invoice,
            'arabic' => $this->arabic,
            'logoDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'logo_path'),
            'stampDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'stamp_path'),
            'verificationUrl' => $this->verification->url($invoice),
            'qrCodeDataUri' => $this->verification->qrCodeDataUri($invoice),
            'orderContext' => $this->orderContext($invoice),
        ];
    }

    /** Presentation only; issued financial and seller snapshots are never mutated. */
    private function orderContext(TaxInvoice $invoice): array
    {
        $reference = trim((string) $invoice->order_reference);
        $heading = 'ORDER ID';
        $headingAr = 'رقم الطلب';
        $referenceLabel = 'Order ID';
        if ($invoice->source_order_id === null) {
            return compact('reference', 'heading', 'headingAr', 'referenceLabel');
        }
        $invoice->loadMissing('sourceOrder.platform');
        $order = $invoice->sourceOrder;
        if ($order === null) {
            return compact('reference', 'heading', 'headingAr', 'referenceLabel');
        }
        $platform = strtolower((string) $order->platform?->name);
        [$heading, $headingAr] = match (true) {
            preg_match('/\bamazon\b/i', $platform) === 1 => ['AMAZON.AE ORDER', 'طلب أمازون'],
            preg_match('/\bnoon\b/i', $platform) === 1 => ['NOON ORDER', 'طلب نون'],
            preg_match('/\bcarrefour\b/i', $platform) === 1 => ['CARREFOUR ORDER', 'طلب كارفور'],
            preg_match('/\bsharaf[\s_-]*dg\b/i', $platform) === 1 => ['SHARAF DG ORDER', 'طلب شرف دي جي'],
            preg_match('/\bmicroless\b/i', $platform) === 1 => ['MICROLESS ORDER', 'طلب مايكروليس'],
            default => ['ORDER ID', 'رقم الطلب'],
        };
        if ($reference === '' || $reference === $order->reference) {
            $external = $order->external_order_number;
            $issuedAt = $invoice->issued_at ?? $invoice->created_at;
            if ($issuedAt !== null) {
                $change = OrderAmendmentLine::query()->where('field', 'external_order_number')
                    ->whereHas('amendment', fn ($query) => $query
                        ->where('order_id', $order->id)->where('created_at', '>', $issuedAt))
                    ->orderBy('id')->first();
                if ($change !== null) {
                    $external = $change->old_value;
                }
            }
            if (filled($external)) {
                $reference = trim((string) $external);
            } elseif ($heading !== 'ORDER ID') {
                $referenceLabel = 'ERP order reference';
            }
        }

        return compact('reference', 'heading', 'headingAr', 'referenceLabel');
    }

    /** @param array<string, mixed> $seller */
    private function imageDataUri(array $seller, string $key): ?string
    {
        $path = $seller[$key] ?? null;

        if (blank($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return null;
        }

        return 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($path));
    }
}
