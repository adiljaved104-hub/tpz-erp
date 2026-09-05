<?php

namespace App\Http\Controllers;

use App\Enums\QuotationPermission;
use App\Models\Quotation;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Quotations\QuotationPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class QuotationPdfController extends Controller
{
    public function __invoke(Request $request, Quotation $quotation, QuotationAuthorization $authorization, QuotationPdfService $pdf): Response
    {
        $authorization->authorize(request()->user(), QuotationPermission::Export, $quotation);
        if ($request->boolean('print')) {
                return response()->view('quotations.document', $pdf->viewData($quotation, 'print'));
        }
        $document = $pdf->render($quotation);
        $filename = $quotation->reference.'.pdf';

        return $document->download($filename);
    }
}
