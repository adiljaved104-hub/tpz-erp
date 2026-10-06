<?php

namespace App\Http\Controllers;

use App\Models\QcCertificate;
use App\Models\QcEvidence;
use App\Services\Qc\QcDocumentService;
use App\Services\Qc\QcInspectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class QcPassportController extends Controller
{
    public function search(Request $request)
    {
        $certificate = null;
        if ($request->filled('q')) {
            $raw = $request->query('q');
            $query = is_string($raw) ? trim($raw) : '';
            if (strlen($query) >= 3 && strlen($query) <= 100 && preg_match('/\A[A-Za-z0-9._ -]+\z/', $query)) {
                $certificate = QcCertificate::query()->whereHas('device', fn ($builder) => $builder->where(fn ($match) => $match->where('reference', strtoupper($query))->orWhere('serial_key', QcInspectionService::serialKey($query))))->orderByDesc('version')->first();
            }
            if ($certificate) {
                return redirect(app(QcDocumentService::class)->url($certificate));
            }
        }

        return response()->view('qc.search', ['searched' => $request->filled('q')])->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function show(string $token)
    {
        $certificate = $this->certificate($token);
        $evidence = QcEvidence::query()->where('inspection_id', $certificate->inspection_id)->where('customer_visible', true)->whereIn('public_id', $certificate->snapshot['evidence'])->get();
        $current = QcCertificate::query()->where('device_id', $certificate->device_id)->orderByDesc('version')->firstOrFail();

        return response()->view('qc.passport', app(QcDocumentService::class)->data($certificate) + ['evidence' => $evidence, 'currentUrl' => app(QcDocumentService::class)->url($current)])
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')->header('X-Content-Type-Options', 'nosniff');
    }

    public function download(string $token)
    {
        $certificate = $this->certificate($token);

        return app(QcDocumentService::class)->certificatePdf($certificate)
            ->download($certificate->snapshot['reference'].'-v'.$certificate->version.'.pdf')
            ->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function evidence(string $token, string $id)
    {
        $certificate = $this->certificate($token);
        abort_unless(in_array($id, $certificate->snapshot['evidence'], true), 404);
        $evidence = QcEvidence::query()->where('inspection_id', $certificate->inspection_id)->where('public_id', $id)->where('customer_visible', true)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($evidence->customer_path), 404);

        return response(Storage::disk('local')->get($evidence->customer_path))->header('Content-Type', 'image/jpeg')->header('X-Content-Type-Options', 'nosniff');
    }

    private function certificate(string $token): QcCertificate
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $token), 404);

        return QcCertificate::query()->where('public_token', $token)->firstOrFail();
    }
}
