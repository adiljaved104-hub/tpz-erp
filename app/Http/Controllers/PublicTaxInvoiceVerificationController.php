<?php

namespace App\Http\Controllers;

use App\Models\TaxInvoice;
use Illuminate\Contracts\View\View;

class PublicTaxInvoiceVerificationController extends Controller
{
    public function __invoke(string $token): View
    {
        $invoice = preg_match('/\A[a-f0-9]{64}\z/', $token) === 1
            ? TaxInvoice::query()->where('verification_token', $token)->first()
            : null;

        return view('invoices.verify', ['invoice' => $invoice]);
    }
}
