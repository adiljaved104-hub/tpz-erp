<?php

namespace App\Services\Invoices;

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
        ];
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
