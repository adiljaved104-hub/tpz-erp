<?php

namespace App\Http\Controllers;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceDocumentService;
use Filament\Notifications\Notification;
use Illuminate\Http\Request;
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
            $documents = app(TaxInvoiceDocumentService::class);

            if ($request->boolean('print')) {
                return response()->view('invoices.tax-invoice', $documents->printViewData($invoice));
            }

            return $documents->pdf($invoice)->download($documents->filename($invoice));
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
}
