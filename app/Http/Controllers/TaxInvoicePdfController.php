<?php

namespace App\Http\Controllers;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\InvoiceVerificationService;
use App\Support\ArabicPdfText;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class TaxInvoicePdfController extends Controller
{
    public function __invoke(Request $request, TaxInvoice $invoice): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        app(InvoiceAuthorization::class)->authorize($user, InvoicePermission::DownloadPdf, $invoice);

        try {
            $invoice->load(['items', 'createdBy']);
            $data = [
                'invoice' => $invoice,
                'arabic' => app(ArabicPdfText::class),
                'logoDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'logo_path'),
                'stampDataUri' => $this->imageDataUri($invoice->seller_snapshot, 'stamp_path'),
                'verificationUrl' => app(InvoiceVerificationService::class)->url($invoice),
                'qrCodeDataUri' => app(InvoiceVerificationService::class)->qrCodeDataUri($invoice),
            ];

            if ($request->boolean('print')) {
                return response()->view('invoices.tax-invoice', [...$data, 'documentMode' => 'print']);
            }

            return Pdf::loadView('invoices.tax-invoice', [...$data, 'documentMode' => 'pdf'])
                ->setPaper('a4')
                ->download(str_replace(' ', '-', $invoice->invoice_number).'.pdf');
        } catch (Throwable $exception) {
            report($exception);

            Notification::make()
                ->danger()
                ->title('Invoice created, but PDF could not be generated')
                ->body('The Invoice is safe. Open it and try Download PDF again.')
                ->send();

            return redirect(TaxInvoiceResource::getUrl('view', ['record' => $invoice]));
        }
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
