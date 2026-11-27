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
        $invoice->loadMissing(['items', 'createdBy', 'latestPaymentEvent']);

        return [
            'invoice' => $invoice,
            'arabic' => $this->arabic,
            'logoDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'logo_path'),
            'stampDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'stamp_path'),
            'verificationUrl' => $this->verification->url($invoice),
            'qrCodeDataUri' => $this->verification->qrCodeDataUri($invoice),
            'orderContext' => $this->orderContext($invoice),
            'footerBranding' => app(InvoiceFooterBrandingService::class)->document($invoice->seller_snapshot['footer_branding'] ?? null),
            'paymentEvent' => $invoice->latestPaymentEvent,
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

        $snapshot = $invoice->seller_snapshot['order_context_snapshot'] ?? null;
        if (is_array($snapshot) && array_key_exists('source_reference', $snapshot)) {
            // All new sourced invoices use their issuance snapshot. Never consult
            // current Orders or amendments for an identifier shown to customers.
            $sourceReference = trim((string) $snapshot['source_reference']);
            $external = trim((string) ($snapshot['external_reference'] ?? ''));
            $platform = strtolower((string) ($snapshot['platform_name'] ?? ''));
        } else {
            // Older invoices predate the snapshot. Reconstruct only when
            // amendment timestamps clearly establish a post-issue change.
            $invoice->loadMissing('sourceOrder.platform');
            $order = $invoice->sourceOrder;
            if ($order === null) {
                return compact('reference', 'heading', 'headingAr', 'referenceLabel');
            }
            $sourceReference = trim((string) $order->reference);
            $platform = strtolower((string) $order->platform?->name);
            $external = trim((string) $order->external_order_number);
            $issuedAt = $invoice->issued_at ?? $invoice->created_at;
            if ($issuedAt !== null) {
                $changes = OrderAmendmentLine::query()->where('field', 'external_order_number')
                    ->whereHas('amendment', fn ($query) => $query
                        ->where('order_id', $order->id)->where('created_at', '>=', $issuedAt))
                    ->with('amendment')->orderBy('id')->get();
                if ($changes->contains(fn (OrderAmendmentLine $line): bool => $line->amendment->created_at->equalTo($issuedAt))) {
                    // Second-resolution timestamps cannot prove which event came
                    // first. Prefer the immutable ERP reference, not a guess.
                    $external = '';
                } elseif ($changes->isNotEmpty()) {
                    $external = trim((string) $changes->first()->old_value);
                }
            }
        }

        [$heading, $headingAr] = match (true) {
            preg_match('/\bamazon\b/i', $platform) === 1 => ['AMAZON.AE ORDER', 'طلب أمازون'],
            preg_match('/\bnoon\b/i', $platform) === 1 => ['NOON ORDER', 'طلب نون'],
            preg_match('/\bcarrefour\b/i', $platform) === 1 => ['CARREFOUR ORDER', 'طلب كارفور'],
            preg_match('/\bsharaf[\s_-]*dg\b/i', $platform) === 1 => ['SHARAF DG ORDER', 'طلب شرف دي جي'],
            preg_match('/\bmicroless\b/i', $platform) === 1 => ['MICROLESS ORDER', 'طلب مايكروليس'],
            default => ['ORDER ID', 'رقم الطلب'],
        };
        if ($reference === '' || $reference === $sourceReference) {
            if ($external !== '') {
                $reference = $external;
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
