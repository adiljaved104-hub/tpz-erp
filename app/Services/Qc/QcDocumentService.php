<?php

namespace App\Services\Qc;

use App\Enums\QcInspectionStatus;
use App\Enums\QcPermission;
use App\Models\QcCertificate;
use App\Models\QcInspection;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\QcAuthorization;
use Barryvdh\DomPDF\Facade\Pdf;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class QcDocumentService
{
    public function url(QcCertificate $certificate): string
    {
        return rtrim((string) config('app.url'), '/').route('qc.verify', ['token' => $certificate->public_token], absolute: false);
    }

    public function data(QcCertificate $certificate): array
    {
        return ['certificate' => $certificate, 'snapshot' => $certificate->snapshot, 'isCurrent' => $certificate->isCurrent(), 'verificationUrl' => $this->url($certificate),
            'qr' => (new QRCode(new QROptions(['outputType' => QRCode::OUTPUT_IMAGE_PNG, 'outputBase64' => true, 'scale' => 5, 'imageTransparent' => false])))->render($this->url($certificate)),
            'logo' => 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('branding/tech-point-zone-logo.png')))];
    }

    public function printable(QcInspection $inspection, User $actor, QcPermission $permission): QcCertificate
    {
        $inspection = $inspection->fresh();
        app(QcAuthorization::class)->authorize($actor, $permission, $inspection);
        $certificate = $inspection->certificate;
        if ($inspection->status !== QcInspectionStatus::Completed || ! $certificate || ($permission === QcPermission::PrintLabel && ! $certificate->isCurrent())) {
            throw ValidationException::withMessages(['record' => 'Only a completed, current QC certificate can be printed as verified.']);
        }

        return $certificate;
    }

    public function pdf(QcInspection $inspection, User $actor): \Barryvdh\DomPDF\PDF
    {
        $certificate = $this->printable($inspection, $actor, QcPermission::PrintCertificate);
        $pdf = Pdf::loadView('qc.certificate', $this->data($certificate))->setPaper('a4');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $dompdf->getCanvas()->page_text(30, 820, $certificate->snapshot['reference'].' - v'.$certificate->version.' | Page {PAGE_NUM} of {PAGE_COUNT}', $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal'), 8, [0.3, 0.4, 0.5]);
        // Render succeeded. Emit bytes only once: CPDF font streams are mutated by output().
        app(ActivityLogger::class)->log('qc.certificate_printed', $actor, $inspection, ['version' => $certificate->version]);

        return $pdf;
    }

    public function labels(array $ids, User $actor): array
    {
        Validator::make(['ids' => $ids], ['ids' => ['required', 'array', 'min:1', 'max:50'], 'ids.*' => ['required', 'integer', 'distinct', 'exists:qc_inspections,id']])->validate();
        $records = QcInspection::query()->with('certificate')->whereKey($ids)->get()->keyBy('id');
        $labels = [];
        foreach ($ids as $id) {
            $labels[] = $this->data($this->printable($records[$id], $actor, QcPermission::PrintLabel));
        }
        foreach ($records as $inspection) {
            app(ActivityLogger::class)->log('qc.label_printed', $actor, $inspection, ['version' => $inspection->version]);
        }
        if (count($labels) > 1) {
            app(ActivityLogger::class)->log('qc.bulk_labels_printed', $actor, null, ['count' => count($labels)]);
        }

        return $labels;
    }
}
