<?php

namespace App\Services\Quotations;

use App\Models\Quotation;
use App\Support\ArabicPdfText;;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use Illuminate\Support\Facades\Storage;

class QuotationPdfService
{
    public function __construct(
        private readonly ArabicPdfText $arabic,
    ) {
    }

    public function render(Quotation $quotation): DomPdf
    {
        $quotation->loadMissing(['items', 'salesperson']);

        return Pdf::loadView(
            'quotations.document',
            $this->viewData($quotation, 'pdf')
        )->setPaper('a4');
    }

    public function viewData(
        Quotation $quotation,
        string $documentMode = 'pdf'
    ): array {
        $quotation->loadMissing(['items', 'salesperson']);

        $seller = $quotation->seller_snapshot ?? [];

        $logo = $this->imageDataUri(
            $seller['logo_path'] ?? null
        );

        $stamp = $this->imageDataUri(
            $seller['stamp_path'] ?? null
        );

        return [
            'quotation' => $quotation,
            'logoDataUri' => $logo,
            'stampDataUri' => $stamp,
            'arabic' => $this->arabic,
            'documentMode' => $documentMode,
        ];
    }

    private function imageDataUri(?string $path): ?string
    {
        if (
            blank($path)
            || ! Storage::disk('public')->exists($path)
        ) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($path);

        if (! in_array(
            $mime,
            [
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
            true
        )) {
            return null;
        }

        return 'data:'
            .$mime
            .';base64,'
            .base64_encode(
                Storage::disk('public')->get($path)
            );
    }
}