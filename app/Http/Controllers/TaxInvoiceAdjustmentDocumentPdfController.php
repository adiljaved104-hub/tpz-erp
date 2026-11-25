<?php

namespace App\Http\Controllers;

use App\Enums\AccountingJournalStatus;
use App\Enums\InvoicePermission;
use App\Models\TaxInvoiceAdjustmentDocument;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TaxInvoiceAdjustmentDocumentPdfController extends Controller
{
    public function __invoke(Request $request, TaxInvoiceAdjustmentDocument $document): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);
        $document->loadMissing(['originalInvoice', 'accountingJournal']);
        app(InvoiceAuthorization::class)->authorize($actor, InvoicePermission::DownloadPdf, $document->originalInvoice);
        abort_unless($document->status === 'issued'
            && $document->accountingJournal?->status === AccountingJournalStatus::Posted, 404);

        $pdf = Pdf::loadView('invoices.tax-adjustment-document', [
            'document' => $document,
            'invoice' => $document->originalInvoice,
        ])->setPaper('a4');

        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '-', $document->document_number).'.pdf';

        return $pdf->download($filename);
    }
}
