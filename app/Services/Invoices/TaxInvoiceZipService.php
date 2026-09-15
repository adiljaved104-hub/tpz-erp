<?php

namespace App\Services\Invoices;

use App\Enums\InvoicePermission;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

class TaxInvoiceZipService
{
    public const MAX_INVOICES = 50;

    public function __construct(
        private readonly InvoiceAuthorization $authorization,
        private readonly TaxInvoiceDocumentService $documents,
    ) {}

    /**
     * @param  EloquentCollection<int, TaxInvoice>|Collection<int, TaxInvoice>  $invoices
     * @param  array<int, int|string>|null  $requestedKeys
     */
    public function download(EloquentCollection|Collection $invoices, User $actor, ?array $requestedKeys = null): BinaryFileResponse
    {
        $invoices = $invoices->values();

        if ($requestedKeys !== null) {
            $requestedKeys = collect($requestedKeys)->map(fn ($key): string => (string) $key)->unique()->values();
            $resolvedKeys = $invoices->map(fn (TaxInvoice $invoice): string => (string) $invoice->getKey())->unique()->values();

            if ($requestedKeys->diff($resolvedKeys)->isNotEmpty()) {
                throw new AuthorizationException('One or more selected Invoices are not available for download.');
            }
        }

        if ($invoices->isEmpty()) {
            throw ValidationException::withMessages(['invoices' => 'Select at least one Invoice.']);
        }

        if ($invoices->count() > self::MAX_INVOICES) {
            throw ValidationException::withMessages([
                'invoices' => 'A maximum of '.self::MAX_INVOICES.' Invoices may be downloaded at once.',
            ]);
        }

        foreach ($invoices as $invoice) {
            $this->authorization->authorize($actor, InvoicePermission::DownloadPdf, $invoice);
        }

        $directory = storage_path('app/private/tax-invoice-exports');
        File::ensureDirectoryExists($directory, 0770, true);
        $path = tempnam($directory, 'tax-invoices-');

        if ($path === false) {
            throw ValidationException::withMessages(['invoices' => 'The Invoice ZIP could not be prepared. Please try again.']);
        }

        $zip = new ZipArchive;
        $zipIsOpen = false;

        try {
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create Tax Invoice ZIP archive.');
            }
            $zipIsOpen = true;

            foreach ($invoices as $invoice) {
                try {
                    $pdf = $this->documents->pdf($invoice)->output();
                } catch (Throwable $exception) {
                    report($exception);

                    throw ValidationException::withMessages([
                        'invoices' => "PDF generation failed for {$invoice->invoice_number}. No ZIP was downloaded.",
                    ]);
                }

                if (! $zip->addFromString($this->documents->filename($invoice), $pdf)) {
                    throw new RuntimeException("Unable to add Invoice {$invoice->getKey()} to ZIP archive.");
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Unable to finalize Tax Invoice ZIP archive.');
            }
            $zipIsOpen = false;
        } catch (Throwable $exception) {
            if ($zipIsOpen) {
                $zip->close();
            }

            File::delete($path);

            if ($exception instanceof ValidationException) {
                throw $exception;
            }

            report($exception);

            throw ValidationException::withMessages(['invoices' => 'The Invoice ZIP could not be generated. No files were downloaded.']);
        }

        $filename = 'tax-invoices-'.now(config('app.timezone'))->format('Y-m-d-His').'.zip';

        return response()->download($path, $filename, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }
}
