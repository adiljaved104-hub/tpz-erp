<?php

namespace App\Services\Invoices;

use App\Models\TaxInvoice;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use LogicException;

class InvoiceVerificationService
{
    public function url(TaxInvoice $invoice): string
    {
        $token = $invoice->verification_token;
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw new LogicException('The issued Invoice does not have a valid verification token.');
        }

        return rtrim((string) config('app.url'), '/')
            .route('invoice.verify', ['token' => $token], absolute: false);
    }

    public function qrCodeDataUri(TaxInvoice $invoice): string
    {
        return (new QRCode(new QROptions([
            'outputType' => QRCode::OUTPUT_IMAGE_PNG,
            'outputBase64' => true,
            'scale' => 5,
            'imageTransparent' => false,
        ])))->render($this->url($invoice));
    }
}
