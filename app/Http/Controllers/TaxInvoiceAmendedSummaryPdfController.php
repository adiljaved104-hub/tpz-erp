<?php

namespace App\Http\Controllers;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TaxInvoiceAmendedSummaryPdfController extends Controller
{
    public function __invoke(Request $request, TaxInvoicePriceAdjustmentProposal $proposal): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $proposal->loadMissing(['invoice', 'decision']);
        $invoice = $proposal->invoice;

        app(InvoiceAuthorization::class)->authorize($actor, InvoicePermission::DownloadPdf, $invoice);
        abort_unless($invoice->status === 'issued' && $proposal->decision?->decision === 'approved', 404);

        // An approved internal review does not issue a VAT credit note or
        // replacement Tax Invoice. This summary MUST NOT masquerade as one.
        $hasIssuedAdjustment = $proposal->documents()->exists();
        $pdf = Pdf::loadView('invoices.amended-tax-invoice-summary', [
            'invoice' => $invoice,
            'proposal' => $proposal,
            'hasIssuedAdjustment' => $hasIssuedAdjustment,
        ])->setPaper('a4');

        $reference = preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->invoice_number);

        return $pdf->download('Amended-Summary-'.trim((string) $reference, '-').'-Request-'.$proposal->id.'.pdf');
    }
}
