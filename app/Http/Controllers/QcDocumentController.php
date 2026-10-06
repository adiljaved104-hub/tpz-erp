<?php

namespace App\Http\Controllers;

use App\Enums\QcPermission;
use App\Models\QcEvidence;
use App\Models\QcInspection;
use App\Services\Authorization\QcAuthorization;
use App\Services\Qc\QcDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class QcDocumentController extends Controller
{
    public function certificate(Request $request, QcInspection $inspection)
    {
        return app(QcDocumentService::class)->pdf($inspection, $request->user())->download($inspection->device->reference.'-v'.$inspection->version.'.pdf');
    }

    public function labels(Request $request)
    {
        try {
            $ids = $request->query('ids', []);
            if (! is_array($ids)) {
                throw ValidationException::withMessages(['ids' => 'Select one or more completed QC records.']);
            }
            $labels = app(QcDocumentService::class)->labels($ids, $request->user());
        } catch (ValidationException $exception) {
            return response()->view('qc.document-error', ['message' => collect($exception->errors())->flatten()->first()], 422);
        }

        return response()->view('qc.labels', compact('labels'));
    }

    public function evidence(Request $request, QcEvidence $evidence)
    {
        app(QcAuthorization::class)->authorize($request->user(), $request->boolean('original') || ! $evidence->customer_visible ? QcPermission::ViewInternalEvidence : QcPermission::ViewCustomerEvidence, $evidence->inspection);
        $path = $request->boolean('original') ? $evidence->original_path : $evidence->customer_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return $request->boolean('original') ? Storage::disk('local')->download($path, 'qc-original-image') : response(Storage::disk('local')->get($path))->header('Content-Type', 'image/jpeg')->header('X-Content-Type-Options', 'nosniff');
    }
}
