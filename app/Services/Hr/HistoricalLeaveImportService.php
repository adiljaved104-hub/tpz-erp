<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\HistoricalLeaveImportBatch;
use App\Models\LeaveType;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrAuthorization;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

class HistoricalLeaveImportService
{
    public const REQUIRED_HEADERS = [
        'Employee ID', 'Actual Joining Date', 'From Date', 'To Date', 'Leave Type',
        'Approval Status', 'Reason', 'Historical Source',
    ];

    public const OPTIONAL_HEADERS = [
        'Chargeable Days', 'Verified Day Count Justification', 'Original Reference',
        'Original Approval Date', 'Original Approver', 'Remarks',
    ];

    private const MAX_FILE_BYTES = 10_485_760;

    private const MAX_ROWS = 5000;

    private const MAX_COLUMNS = 24;

    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HistoricalLeaveAuthorization $access,
        private readonly HistoricalLeavePreviewService $previewer,
        private readonly HistoricalLeaveService $records,
        private readonly JoiningDateLeaveBalanceService $balances,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array<string,mixed> */
    public function preview(UploadedFile $file, User $actor): array
    {
        $this->authorize($actor);
        $path = $this->assertWorkbook($file);
        $hash = hash_file('sha256', $path);
        $alreadyImported = HistoricalLeaveImportBatch::query()
            ->where('file_sha256', $hash)->where('status', 'imported')->exists();
        $rawRows = $this->readRows($path);
        $rows = [];
        $seenKeys = [];
        $annualProjection = [];

        foreach ($rawRows as $entry) {
            $row = $this->inspectRow($entry['raw'], $entry['source_row_number'], $actor);
            if ($alreadyImported) {
                $row['status'] = 'duplicate';
                $row['errors'] = ['This exact workbook was already imported successfully.'];
            } elseif ($row['data'] !== null) {
                $data = $row['data'];
                $key = implode('|', [
                    $data['employee_id'], $data['leave_type_id'], $data['from_date'], $data['to_date'],
                ]);
                if (isset($seenKeys[$key])) {
                    $row['status'] = 'duplicate';
                    $row['errors'] = ['This employee, leave type, and date range already appears in an earlier workbook row.'];
                } else {
                    $seenKeys[$key] = $row['source_row_number'];
                    if ($row['status'] === 'valid') {
                        $this->applyBatchProjection($row, $annualProjection);
                    }
                }
            }
            $rows[] = $row;
        }

        $this->markInFileOverlaps($rows);
        $valid = collect($rows)->where('status', 'valid');

        return [
            'file_name' => basename(str_replace('\\', '/', $file->getClientOriginalName())),
            'file_sha256' => $hash,
            'already_imported' => $alreadyImported,
            'rows' => $rows,
            'total_rows' => count($rows),
            'valid_rows' => $valid->count(),
            'invalid_rows' => collect($rows)->where('status', 'invalid')->count(),
            'duplicate_rows' => collect($rows)->where('status', 'duplicate')->count(),
            'estimated_annual_days' => number_format((float) $valid->sum('annual_days'), 2, '.', ''),
            'estimated_unpaid_days' => number_format((float) $valid->sum('unpaid_days'), 2, '.', ''),
            'database_writes' => false,
        ];
    }

    /** @param array<string,mixed> $preview */
    public function import(UploadedFile $file, string $expectedHash, User $actor): HistoricalLeaveImportBatch
    {
        $this->authorize($actor);
        $preview = $this->preview($file, $actor);
        if (! hash_equals((string) $preview['file_sha256'], $expectedHash)) {
            throw ValidationException::withMessages(['importFile' => 'The uploaded file changed after preview. Preview it again before importing.']);
        }
        if ($preview['already_imported'] || $preview['duplicate_rows'] > 0) {
            throw ValidationException::withMessages(['importFile' => 'The workbook contains duplicate rows or has already been imported. Correct or remove duplicates before importing.']);
        }
        if ($preview['invalid_rows'] > 0 || $preview['valid_rows'] !== $preview['total_rows']) {
            throw ValidationException::withMessages(['importFile' => 'Every row must be valid and unique before this all-or-nothing import can be confirmed.']);
        }

        try {
            return DB::transaction(function () use ($preview, $actor): HistoricalLeaveImportBatch {
                $this->authorize($actor);
                $employeeIds = collect($preview['rows'])->pluck('data.employee_id')->unique()->sort()->values();
                $lockedEmployees = Employee::query()->whereIn('id', $employeeIds)->orderBy('id')->lockForUpdate()->get();
                if ($lockedEmployees->count() !== $employeeIds->count()) {
                    throw ValidationException::withMessages(['importFile' => 'One or more employees changed while the import was being confirmed. Preview the file again.']);
                }

                if (HistoricalLeaveImportBatch::query()->where('file_sha256', $preview['file_sha256'])->exists()) {
                    throw ValidationException::withMessages(['importFile' => 'This workbook has already been submitted for import.']);
                }

                $batch = HistoricalLeaveImportBatch::query()->create([
                    'batch_uuid' => (string) Str::uuid(),
                    'uploaded_by_user_id' => $actor->id,
                    'original_filename' => $preview['file_name'],
                    'file_sha256' => $preview['file_sha256'],
                    'status' => 'importing',
                    'total_rows' => $preview['total_rows'],
                    'valid_rows' => $preview['valid_rows'],
                    'invalid_rows' => 0,
                    'imported_rows' => 0,
                    'preview_summary' => [
                        'annual_days' => $preview['estimated_annual_days'],
                        'unpaid_days' => $preview['estimated_unpaid_days'],
                    ],
                ]);

                foreach ($preview['rows'] as $row) {
                    $data = $row['data'];
                    $data['confirmed'] = true;
                    $data['idempotency_key'] = (string) Str::uuid();
                    $this->records->recordFromImport($data, $actor, $batch, $row['source_row_number']);
                }

                $batch->forceFill([
                    'status' => 'imported',
                    'imported_rows' => $preview['total_rows'],
                    'confirmed_at' => now(),
                ])->save();
                $this->activity->log('hr.historical_leave_import_batch_completed', $actor, $batch, [
                    'batch_uuid' => $batch->batch_uuid,
                    'rows_imported' => $preview['total_rows'],
                    'annual_days' => $preview['estimated_annual_days'],
                    'unpaid_days' => $preview['estimated_unpaid_days'],
                ]);

                return $batch->fresh();
            }, 3);
        } catch (QueryException $exception) {
            $message = mb_strtolower($exception->getMessage());
            if (str_contains($message, 'hist_leave_batch_file_sha256_uq')
                || str_contains($message, 'historical_leave_import_batches.file_sha256')) {
                throw ValidationException::withMessages(['importFile' => 'This workbook has already been imported.']);
            }
            throw $exception;
        }
    }

    public function downloadTemplate(User $actor): StreamedResponse
    {
        $this->authorize($actor);

        return response()->streamDownload(function (): void {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Historical Leave');
            $headers = [...self::REQUIRED_HEADERS, ...self::OPTIONAL_HEADERS];
            foreach ($headers as $offset => $header) {
                $sheet->setCellValue(Coordinate::stringFromColumnIndex($offset + 1).'1', $header);
            }
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1');
            $sheet->getStyle('A1:'.Coordinate::stringFromColumnIndex(count($headers)).'1')->getFont()->setBold(true);
            $instructions = $spreadsheet->createSheet();
            $instructions->setTitle('Instructions');
            $instructions->fromArray([
                ['Historical Leave Import Instructions'],
                ['Use one leave entry per row. Dates must be YYYY-MM-DD or real Excel dates.'],
                ['Employee ID must match the ERP employee code and the actual joining date must match the verified date.'],
                ['Leave Type must be Annual or Unpaid. Status must be Pending, Approved, Rejected, or Cancelled.'],
                ['Approved rows require the importer to also have Leave Approve permission.'],
                ['Do not repeat employee/type/date ranges. Every row is revalidated before the all-or-nothing import.'],
                ['Chargeable Days and Verified Day Count Justification are only for dates without a schedule calculation.'],
                ['Preview does not write records. Confirmed imports are permanently audited and the same workbook cannot be imported twice.'],
            ]);
            $spreadsheet->setActiveSheetIndex(0);
            (new Xlsx($spreadsheet))->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, 'historical_leave_import_template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @param array<string,mixed> $preview */
    public function downloadRejectedRows(array $preview, User $actor): StreamedResponse
    {
        $this->authorize($actor);
        $rejected = collect($preview['rows'] ?? [])->whereIn('status', ['invalid', 'duplicate']);

        return response()->streamDownload(function () use ($rejected): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Source Row', 'Employee ID', 'Status', 'Explanation']);
            foreach ($rejected as $row) {
                fputcsv($output, [
                    $row['source_row_number'] ?? '',
                    $this->safeCsv((string) ($row['employee_code'] ?? '')),
                    $row['status'] ?? 'invalid',
                    $this->safeCsv(implode(' ', $row['errors'] ?? [])),
                ]);
            }
            fclose($output);
        }, 'historical_leave_import_rejected_rows.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorize(User $actor): void
    {
        throw_unless($this->authorization->allows($actor, HrPermission::LeaveHistoricalImport), AuthorizationException::class);
    }

    private function assertWorkbook(UploadedFile $file): string
    {
        if (mb_strtolower($file->getClientOriginalExtension()) !== 'xlsx' || $file->getSize() > self::MAX_FILE_BYTES) {
            throw ValidationException::withMessages(['importFile' => 'Upload an .xlsx workbook no larger than 10 MB.']);
        }

        $path = $file->getRealPath();
        if (! is_string($path) || ! is_file($path) || ! is_readable($path)) {
            throw ValidationException::withMessages(['importFile' => 'The uploaded workbook could not be read. Upload it again.']);
        }
        if (class_exists(ZipArchive::class)) {
            $zip = new ZipArchive;
            if ($zip->open($path) !== true) {
                throw ValidationException::withMessages(['importFile' => 'The uploaded file is not a valid XLSX workbook.']);
            }
            $totalUncompressedBytes = 0;
            if ($zip->numFiles > 1000) {
                $zip->close();
                throw ValidationException::withMessages(['importFile' => 'The workbook contains too many embedded files.']);
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $totalUncompressedBytes += (int) ($stat['size'] ?? 0);
                if (str_ends_with(mb_strtolower((string) ($stat['name'] ?? '')), 'vbaproject.bin') || $totalUncompressedBytes > 52_428_800) {
                    $zip->close();
                    throw ValidationException::withMessages(['importFile' => 'Macros or oversized workbook content are not accepted.']);
                }
            }
            $zip->close();
        }

        return $path;
    }

    /** @return array<int,array{source_row_number:int,raw:array<string,mixed>}> */
    private function readRows(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $workbook = $reader->load($path);
            $sheet = $workbook->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
            if ($highestRow < 2 || $highestRow > self::MAX_ROWS + 1 || $highestColumn > self::MAX_COLUMNS) {
                throw ValidationException::withMessages(['importFile' => 'The workbook must contain between 1 and 5,000 data rows and no more than 24 columns.']);
            }

            $headerValues = [];
            for ($column = 1; $column <= $highestColumn; $column++) {
                $headerValues[$column] = $sheet->getCell(Coordinate::stringFromColumnIndex($column).'1')->getValue();
            }
            $headers = $this->normalizeHeaders($headerValues);
            $missing = collect(self::REQUIRED_HEADERS)->reject(fn (string $header): bool => in_array($this->header($header), $headers, true));
            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages(['importFile' => 'Missing required columns: '.$missing->implode(', ').'. Download the current template and try again.']);
            }

            $rows = [];
            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $raw = [];
                $hasData = false;
                for ($column = 1; $column <= $highestColumn; $column++) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$rowNumber);
                    if ($cell->isFormula()) {
                        throw ValidationException::withMessages(['importFile' => "Formulas are not accepted (row {$rowNumber}). Paste values only."]);
                    }
                    $value = $cell->getValue();
                    $hasData = $hasData || ! blank($value);
                    if (($headers[$column] ?? '') !== '') {
                        $raw[$headers[$column]] = $value;
                    }
                }
                if ($hasData) {
                    $rows[] = ['source_row_number' => $rowNumber, 'raw' => $raw];
                }
            }
            $workbook->disconnectWorksheets();

            if ($rows === []) {
                throw ValidationException::withMessages(['importFile' => 'The workbook contains no leave rows.']);
            }

            return $rows;
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw ValidationException::withMessages(['importFile' => 'The workbook is unreadable or invalid. Download the current template and upload a valid .xlsx file.']);
        }
    }

    /** @param array<int,mixed> $rawHeaders @return array<int,string> */
    private function normalizeHeaders(array $rawHeaders): array
    {
        $headers = [];
        $seen = [];
        foreach ($rawHeaders as $column => $raw) {
            $header = $this->header((string) $raw);
            $header = match ($header) {
                'employee_code', 'employee' => $this->header('Employee ID'),
                'joining_date', 'verified_joining_date' => $this->header('Actual Joining Date'),
                'start_date' => $this->header('From Date'),
                'end_date' => $this->header('To Date'),
                'status' => $this->header('Approval Status'),
                'source' => $this->header('Historical Source'),
                'manual_override_reason', 'day_count_justification' => $this->header('Verified Day Count Justification'),
                default => $header,
            };
            if ($header !== '' && isset($seen[$header])) {
                throw ValidationException::withMessages(['importFile' => 'The workbook contains duplicate column headings.']);
            }
            if ($header !== '') {
                $seen[$header] = true;
            }
            $headers[$column] = $header;
        }

        return $headers;
    }

    private function header(string $value): string
    {
        return Str::of($value)->trim()->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->value();
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function inspectRow(array $raw, int $sourceRow, User $actor): array
    {
        $errors = [];
        $employeeCode = trim((string) ($raw[$this->header('Employee ID')] ?? ''));
        $employee = $employeeCode === '' ? null : $this->access->employeeQuery($actor, HrPermission::LeaveHistoricalImport, excludeSelf: true)
            ->where('employee_id', $employeeCode)->first();
        if (! $employee instanceof Employee) {
            $errors[] = 'Employee ID is not available in your authorized import scope.';
        }

        $actualJoiningDate = $this->dateValue($raw[$this->header('Actual Joining Date')] ?? null, $errors, 'Actual Joining Date');
        if ($employee instanceof Employee) {
            if ($employee->joining_date === null || $employee->joining_date_verified_at === null) {
                $errors[] = 'The employee does not have a verified joining date.';
            } elseif ($actualJoiningDate !== null && $employee->joining_date->toDateString() !== $actualJoiningDate) {
                $errors[] = 'Actual Joining Date does not match the employee verified in the ERP.';
            }
        }

        $from = $this->dateValue($raw[$this->header('From Date')] ?? null, $errors, 'From Date');
        $to = $this->dateValue($raw[$this->header('To Date')] ?? null, $errors, 'To Date');
        $typeText = trim((string) ($raw[$this->header('Leave Type')] ?? ''));
        $typeKey = Str::of($typeText)->lower()->replaceMatches('/[^a-z0-9]+/', '')->value();
        $type = LeaveType::query()->whereIn('code', ['annual', 'unpaid'])->where('status', true)
            ->get()->first(fn (LeaveType $candidate): bool => $typeKey === Str::of($candidate->code)->lower()->replaceMatches('/[^a-z0-9]+/', '')->value()
                || $typeKey === Str::of($candidate->name)->lower()->replaceMatches('/[^a-z0-9]+/', '')->value());
        if (! $type instanceof LeaveType) {
            $errors[] = 'Leave Type must identify an active Annual or Unpaid leave type.';
        }

        $status = Str::lower(trim((string) ($raw[$this->header('Approval Status')] ?? '')));
        if (! in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) {
            $errors[] = 'Approval Status must be Pending, Approved, Rejected, or Cancelled.';
        }
        $reason = trim((string) ($raw[$this->header('Reason')] ?? ''));
        $source = trim((string) ($raw[$this->header('Historical Source')] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 2000) {
            $errors[] = 'Reason is required and must be no more than 2,000 characters.';
        }
        if ($source === '' || mb_strlen($source) > 120) {
            $errors[] = 'Historical Source is required and must be no more than 120 characters.';
        }

        $chargeable = $raw[$this->header('Chargeable Days')] ?? null;
        $chargeable = blank($chargeable) ? null : trim((string) $chargeable);
        if ($chargeable !== null && ! preg_match('/^\d{1,3}(?:\.\d{1,2})?$/', $chargeable)) {
            $errors[] = 'Chargeable Days must be a positive number with up to two decimal places.';
        }
        $manualReason = trim((string) ($raw[$this->header('Verified Day Count Justification')] ?? ''));
        $originalApprovedAt = $this->dateValue($raw[$this->header('Original Approval Date')] ?? null, $errors, 'Original Approval Date', required: false);
        $originalReference = trim((string) ($raw[$this->header('Original Reference')] ?? ''));
        $originalApprover = trim((string) ($raw[$this->header('Original Approver')] ?? ''));
        $remarks = trim((string) ($raw[$this->header('Remarks')] ?? ''));
        if (mb_strlen($originalReference) > 255 || mb_strlen($originalApprover) > 255 || mb_strlen($remarks) > 10000) {
            $errors[] = 'An optional reference, approver, or remarks field exceeds its allowed length.';
        }

        $data = null;
        $preview = null;
        $isExistingRecordDuplicate = false;
        if ($errors === [] && $employee instanceof Employee && $type instanceof LeaveType && $from !== null && $to !== null) {
            $duplicate = $this->records->hasExactExistingRecord($employee->id, $type->id, $from, $to);
            if ($duplicate) {
                $isExistingRecordDuplicate = true;
                $errors[] = 'An exact employee, leave type, and date-range record already exists, regardless of its approval status.';
            } else {
                $data = [
                    'employee_id' => $employee->id,
                    'actual_joining_date' => $actualJoiningDate,
                    'leave_type_id' => $type->id,
                    'from_date' => $from,
                    'to_date' => $to,
                    'chargeable_days' => $chargeable,
                    'manual_override_reason' => $manualReason,
                    'approval_status' => $status,
                    'reason' => $reason,
                    'historical_source' => $source,
                    'original_reference' => $originalReference === '' ? null : $originalReference,
                    'original_approved_at' => $originalApprovedAt,
                    'original_approver' => $originalApprover === '' ? null : $originalApprover,
                    'remarks' => $remarks === '' ? null : $remarks,
                    'idempotency_key' => (string) Str::uuid(),
                    'confirmed' => true,
                ];
                try {
                    $preview = $this->previewer->preview($data, $actor, HrPermission::LeaveHistoricalImport);
                } catch (AuthorizationException $exception) {
                    throw $exception;
                } catch (ValidationException $exception) {
                    $errors = collect($exception->errors())->flatten()->map(fn ($message): string => (string) $message)->all();
                    $data = null;
                }
            }
        }

        $balanceAffecting = in_array($status, ['pending', 'approved'], true);
        $annualDays = $balanceAffecting && $type?->consumes_annual_entitlement && $preview !== null ? (float) $preview['chargeable_days'] : 0.0;
        $unpaidDays = $balanceAffecting && $type?->code === 'unpaid' && $preview !== null ? (float) $preview['chargeable_days'] : 0.0;

        return [
            'source_row_number' => $sourceRow,
            'employee_code' => $employee?->employee_id,
            'employee_name' => $employee?->name,
            'status' => $isExistingRecordDuplicate ? 'duplicate' : ($errors === [] ? 'valid' : 'invalid'),
            'errors' => $errors,
            'data' => $data,
            'annual_days' => $annualDays,
            'unpaid_days' => $unpaidDays,
            'chargeable_days' => $preview['chargeable_days'] ?? null,
            'segments' => $preview['segments'] ?? [],
            'warnings' => $preview['warnings'] ?? [],
        ];
    }

    /** @param array<int,string> $errors */
    private function dateValue(mixed $value, array &$errors, string $field, bool $required = true): ?string
    {
        if (blank($value)) {
            if ($required) {
                $errors[] = "{$field} is required.";
            }

            return null;
        }
        try {
            if (is_int($value) || is_float($value)) {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            }
            $text = trim((string) $value);
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $text);
            if (! $date || $date->format('Y-m-d') !== $text) {
                throw new \InvalidArgumentException;
            }

            return $date->toDateString();
        } catch (Throwable) {
            $errors[] = "{$field} must be a real Excel date or ISO YYYY-MM-DD.";

            return null;
        }
    }

    /** @param array<string,mixed> $row @param array<string,float> $projection */
    private function applyBatchProjection(array &$row, array &$projection): void
    {
        if (($row['annual_days'] ?? 0) <= 0 || ! is_array($row['data'])
            || ! in_array($row['data']['approval_status'], ['pending', 'approved'], true)) {
            return;
        }
        $employee = Employee::query()->find($row['data']['employee_id']);
        if (! $employee instanceof Employee) {
            $row['status'] = 'invalid';
            $row['errors'][] = 'The employee was not found while projecting the batch balance.';

            return;
        }
        $employeeId = $row['data']['employee_id'];
        $withinLimit = true;
        foreach ($row['segments'] as $segment) {
            $key = $employeeId.'|'.$segment['period_start'];
            $base = (float) $this->balances->forDate($employee, $segment['start'])->availableAfterPending;
            if ($row['data']['approval_status'] === 'pending'
                && (($projection[$key] ?? 0.0) + (float) $segment['days']) > $base) {
                $withinLimit = false;
            }
        }
        if (! $withinLimit) {
            $row['status'] = 'invalid';
            $row['errors'][] = 'Combined pending Annual Leave rows in this workbook exceed the employee’s available entitlement for at least one anniversary cycle.';

            return;
        }
        foreach ($row['segments'] as $segment) {
            $key = $employeeId.'|'.$segment['period_start'];
            $projection[$key] = ($projection[$key] ?? 0.0) + (float) $segment['days'];
        }
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function markInFileOverlaps(array &$rows): void
    {
        $candidates = collect($rows)->filter(fn (array $row): bool => $row['status'] === 'valid'
            && in_array($row['data']['approval_status'], ['pending', 'approved'], true))
            ->sortBy(fn (array $row): string => $row['data']['employee_id'].'|'.$row['data']['from_date'].'|'.$row['source_row_number']);
        $seen = [];
        foreach ($candidates as $row) {
            $employee = (string) $row['data']['employee_id'];
            foreach ($seen[$employee] ?? [] as $prior) {
                if ($row['data']['from_date'] <= $prior['to'] && $row['data']['to_date'] >= $prior['from']) {
                    foreach ([$row['source_row_number'], $prior['row']] as $number) {
                        foreach ($rows as &$source) {
                            if ($source['source_row_number'] === $number) {
                                $source['status'] = 'invalid';
                                $source['errors'][] = 'This row overlaps another pending or approved leave row in the same workbook.';
                            }
                        }
                        unset($source);
                    }
                }
            }
            $seen[$employee][] = ['row' => $row['source_row_number'], 'from' => $row['data']['from_date'], 'to' => $row['data']['to_date']];
        }
    }

    /** @param array<int,string> $values */
    private function safeCsv(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
