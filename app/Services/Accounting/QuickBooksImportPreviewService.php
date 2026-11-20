<?php

namespace App\Services\Accounting;

use App\Enums\AccountingPermission;
use App\Models\AccountingImportBatch;
use App\Models\AccountingImportBatchItem;
use App\Models\AccountingImportLink;
use App\Models\AccountingImportSourceIdentity;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\AccountingAuthorization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use SimpleXMLElement;
use XMLReader;

class QuickBooksImportPreviewService
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly AccountingAuthorization $authorization,
        private readonly ActivityLogger $activity,
    ) {}

    public function createBatch(UploadedFile $file, string $sourceCompanyIdentity, User $actor): AccountingImportBatch
    {
        $this->authorization->authorize($actor, AccountingPermission::ManageImportCenter);

        $extension = strtolower($file->getClientOriginalExtension());
        if (! in_array($extension, ['xml', 'qbxml', 'json', 'jsonl', 'ndjson', 'csv'], true)) {
            throw ValidationException::withMessages(['file' => 'Upload a qbXML/XML response or structured JSON, NDJSON, or CSV export. QuickBooks company files are not accepted.']);
        }

        if ($file->getSize() === false || $file->getSize() > 100 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => 'Each source export must be 100 MB or smaller. Split larger exports into bounded date/type batches.']);
        }

        $identity = trim($sourceCompanyIdentity);
        if ($identity === '' || mb_strlen($identity) > 190) {
            throw ValidationException::withMessages(['sourceCompanyIdentity' => 'Enter a stable non-secret source-company label for duplicate detection.']);
        }

        $disk = Storage::disk('local');
        $directory = 'accounting-imports/'.str()->uuid();
        $path = $disk->putFileAs($directory, $file, 'source.'.$extension);
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('The export could not be stored in private application storage.');
        }

        $absolutePath = $disk->path($path);
        $batch = AccountingImportBatch::query()->create([
            'source_company_fingerprint' => hash('sha256', mb_strtolower($identity)),
            'source_format' => $extension,
            'qbxml_version' => in_array($extension, ['xml', 'qbxml'], true)
                && preg_match('/<\?qbxml\s+version=["\']([^"\']+)["\']/i', (string) file_get_contents($absolutePath, false, null, 0, 2048), $versionMatch) === 1
                ? $versionMatch[1]
                : null,
            'private_file_path' => $path,
            'file_sha256' => hash_file('sha256', $absolutePath),
            'status' => 'uploaded',
            'dry_run' => true,
            'created_by_user_id' => $actor->id,
        ]);

        $this->activity->log('accounting.import_batch.created', $actor, $batch, [
            'source_format' => $extension,
            'file_sha256' => $batch->file_sha256,
            'dry_run' => true,
        ]);

        return $batch;
    }

    public function preview(AccountingImportBatch $batch, User $actor): AccountingImportBatch
    {
        $this->authorization->authorize($actor, AccountingPermission::ManageImportCenter);
        $batch->refresh();

        if (! in_array($batch->status, ['uploaded', 'previewing', 'failed'], true)) {
            throw ValidationException::withMessages(['batch' => 'Only uploaded or interrupted preview batches can be resumed.']);
        }

        $path = Storage::disk('local')->path($batch->private_file_path);
        if (! is_file($path) || ! hash_equals($batch->file_sha256, hash_file('sha256', $path))) {
            $batch->forceFill(['status' => 'failed', 'error_summary' => ['source_file' => 'The private export file is missing or its checksum changed.']])->save();
            throw ValidationException::withMessages(['file' => 'The source export checksum could not be verified.']);
        }

        $batch->forceFill([
            'status' => 'previewing',
            'preview_started_at' => $batch->preview_started_at ?? now(),
            'preview_finished_at' => null,
            'error_summary' => null,
        ])->save();

        try {
            $count = 0;
            $chunk = [];
            foreach ($this->readRecords($path, $batch->source_format) as $record) {
                $chunk[] = $record;
                $count++;

                if (count($chunk) >= self::CHUNK_SIZE) {
                    $this->stageChunk($batch, $chunk);
                    $chunk = [];
                    $this->saveProgress($batch, $count);
                }
            }

            if ($chunk !== []) {
                $this->stageChunk($batch, $chunk);
            }

            $batch->refresh();
            $items = $batch->items();
            $batch->forceFill([
                'status' => 'previewed',
                'record_count' => $count,
                'staged_count' => (clone $items)->where('status', 'previewed')->count(),
                'duplicate_count' => (clone $items)->where('status', 'duplicate')->count(),
                'changed_count' => (clone $items)->where('status', 'needs_review')->count(),
                'invalid_count' => (clone $items)->where('status', 'invalid')->count(),
                'checkpoint' => ['file_sha256' => $batch->file_sha256, 'records_read' => $count],
                'preview_finished_at' => now(),
            ])->save();

            $this->activity->log('accounting.import_batch.previewed', $actor, $batch, [
                'record_count' => $count,
                'staged_count' => $batch->staged_count,
                'duplicate_count' => $batch->duplicate_count,
                'changed_count' => $batch->changed_count,
                'invalid_count' => $batch->invalid_count,
            ]);

            return $batch;
        } catch (\Throwable $exception) {
            $batch->forceFill([
                'status' => 'failed',
                'error_summary' => ['preview' => mb_substr($exception->getMessage(), 0, 1000)],
            ])->save();

            throw $exception;
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readRecords(string $path, string $format): \Generator
    {
        if (in_array($format, ['xml', 'qbxml'], true)) {
            yield from $this->readQbXml($path);

            return;
        }

        if (in_array($format, ['jsonl', 'ndjson'], true)) {
            $handle = fopen($path, 'rb');
            if (! is_resource($handle)) {
                throw new RuntimeException('The NDJSON export could not be opened.');
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    if (trim($line) === '') {
                        continue;
                    }

                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($decoded)) {
                        throw new RuntimeException('Each NDJSON line must contain a structured record object.');
                    }

                    yield $decoded;
                }
            } finally {
                fclose($handle);
            }

            return;
        }

        if ($format === 'csv') {
            yield from $this->readCsv($path);

            return;
        }

        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $records = $decoded['records'] ?? $decoded;
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('A JSON export must be an array of records or an object containing a records array.');
        }

        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new RuntimeException('Every JSON export record must be an object.');
            }

            yield $record;
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new RuntimeException('The structured CSV export could not be opened.');
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers) || $headers === []) {
                throw new RuntimeException('The structured CSV export has no header row.');
            }

            $normalizedHeaders = array_map(fn ($header): string => strtolower(trim((string) $header)), $headers);
            $hasType = array_intersect($normalizedHeaders, ['source_object_type', 'record_type', 'type']) !== [];
            $hasId = array_intersect($normalizedHeaders, ['source_record_id', 'txnid', 'listid']) !== [];
            if (! $hasType || ! $hasId) {
                throw new RuntimeException('CSV preview requires explicit record type and source ID columns (source_object_type/record_type and source_record_id/TxnID/ListID). Report-only CSV files cannot be imported as transactions.');
            }

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === []) {
                    continue;
                }

                $row = array_pad($row, count($headers), null);
                yield array_combine($headers, array_slice($row, 0, count($headers)));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function readQbXml(string $path): \Generator
    {
        $reader = new XMLReader;
        if (! $reader->open($path, null, LIBXML_NONET | LIBXML_NOBLANKS)) {
            throw new RuntimeException('The qbXML response could not be opened.');
        }

        try {
            while ($reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT || ! str_ends_with($reader->name, 'Ret')) {
                    continue;
                }

                $xml = $reader->readOuterXml();
                if ($xml === '') {
                    continue;
                }

                $node = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOBLANKS);
                if (! $node instanceof SimpleXMLElement) {
                    throw new RuntimeException('A qbXML response record could not be parsed safely.');
                }

                $payload = $this->xmlToArray($node);
                if (! is_array($payload) || ($payload['TxnID'] ?? $payload['ListID'] ?? null) === null) {
                    // Nested line/summary Ret nodes are already preserved in the parent transaction's raw XML/payload.
                    continue;
                }

                yield [
                    'source_object_type' => preg_replace('/Ret$/', '', $node->getName()),
                    'record_payload' => $payload,
                    'raw_source_xml' => $xml,
                ];
            }
        } finally {
            $reader->close();
        }
    }

    private function xmlToArray(SimpleXMLElement $node): array|string
    {
        $result = [];
        foreach ($node->attributes() as $name => $value) {
            $result['@attributes'][(string) $name] = (string) $value;
        }

        foreach ($node->children() as $child) {
            $name = $child->getName();
            $value = $child->count() > 0 || $child->attributes()->count() > 0
                ? $this->xmlToArray($child)
                : (string) $child;

            if (array_key_exists($name, $result)) {
                if (! is_array($result[$name]) || ! array_is_list($result[$name])) {
                    $result[$name] = [$result[$name]];
                }
                $result[$name][] = $value;
            } else {
                $result[$name] = $value;
            }
        }

        if ($result === []) {
            return (string) $node;
        }

        return $result;
    }

    /** @param array<int, array<string, mixed>> $records */
    private function stageChunk(AccountingImportBatch $batch, array $records): void
    {
        DB::transaction(function () use ($batch, $records): void {
            foreach ($records as $record) {
                $payload = $record['record_payload'] ?? $record;
                if (! is_array($payload)) {
                    $payload = ['value' => $payload];
                }

                $objectType = $this->stringField($record, ['source_object_type', 'record_type', 'type'])
                    ?? $this->stringField($payload, ['source_object_type', 'record_type', 'type'])
                    ?? 'Unknown';
                $sourceId = $this->stringField($record, ['source_record_id', 'TxnID', 'ListID', 'txnid', 'listid'])
                    ?? $this->stringField($payload, ['source_record_id', 'TxnID', 'ListID', 'txnid', 'listid']);
                $canonicalPayload = $this->canonicalize($payload);
                $payloadJson = json_encode($canonicalPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
                $payloadHash = hash('sha256', $payloadJson);
                $errors = [];

                if ($sourceId === null) {
                    $errors[] = 'Required source identity TxnID/ListID/source_record_id was not present; values were retained for review.';
                    $objectType = 'Unidentified';
                    $sourceId = 'unidentified:'.$payloadHash;
                }

                $editSequence = $this->stringField($payload, ['EditSequence', 'edit_sequence']);
                $identityKey = [
                    'source_company_fingerprint' => $batch->source_company_fingerprint,
                    'source_object_type' => mb_substr($objectType, 0, 64),
                    'source_record_id' => $sourceId,
                ];
                $identity = AccountingImportSourceIdentity::query()->firstOrCreate($identityKey, [
                    'latest_edit_sequence' => $editSequence,
                    'latest_payload_sha256' => $payloadHash,
                ]);

                $itemStatus = $errors === [] ? 'previewed' : 'invalid';
                if (! $identity->wasRecentlyCreated) {
                    if (hash_equals($identity->latest_payload_sha256, $payloadHash)) {
                        $itemStatus = 'duplicate';
                    } else {
                        $itemStatus = 'needs_review';
                        $errors[] = 'This source ID has a changed payload or EditSequence since an earlier export; both snapshots are preserved for explicit resolution.';
                        $identity->forceFill([
                            'latest_edit_sequence' => $editSequence,
                            'latest_payload_sha256' => $payloadHash,
                        ])->save();
                    }
                }

                $existing = AccountingImportBatchItem::query()->where('batch_id', $batch->id)->where('source_identity_id', $identity->id)->exists();
                if ($existing) {
                    continue;
                }

                $item = AccountingImportBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'source_identity_id' => $identity->id,
                    'payload_sha256' => $payloadHash,
                    'source_edit_sequence' => $editSequence,
                    'source_reference' => $this->stringField($payload, ['RefNumber', 'reference_number', 'DocNumber']),
                    'source_transaction_date' => $this->dateField($payload, ['TxnDate', 'bill_date', 'transaction_date']),
                    'source_due_date' => $this->dateField($payload, ['DueDate', 'due_date']),
                    'source_currency' => $this->currencyField($payload),
                    'source_exchange_rate' => $this->decimalField($payload, ['ExchangeRate', 'exchange_rate']),
                    'record_payload' => $canonicalPayload,
                    'status' => $itemStatus,
                    'validation_errors' => $errors === [] ? null : $errors,
                ]);

                $this->stageLinks($item, $payload);
            }
        }, 3);
    }

    private function stageLinks(AccountingImportBatchItem $item, array $payload): void
    {
        foreach (['LinkedTxn', 'LinkedTxnRet', 'AppliedToTxnRet', 'AppliedToTxnAdd', 'SetCreditList', 'SetCredit', 'links'] as $container) {
            if (! array_key_exists($container, $payload)) {
                continue;
            }

            $this->stageLinkNodes($item, $container, $payload[$container]);
        }
    }

    private function stageLinkNodes(AccountingImportBatchItem $item, string $relationshipType, mixed $value): void
    {
        if (! is_array($value)) {
            return;
        }

        if (! array_is_list($value)) {
            $linkedId = $this->stringField($value, ['TxnID', 'TxnId', 'CreditTxnID', 'CreditTxnId', 'ListID', 'source_record_id']);
            if ($linkedId !== null) {
                AccountingImportLink::query()->firstOrCreate([
                    'batch_item_id' => $item->id,
                    'relationship_type' => $relationshipType,
                    'linked_source_record_id' => $linkedId,
                ], [
                    'linked_object_type' => $this->stringField($value, ['TxnType', 'source_object_type']),
                    'linked_line_id' => $this->stringField($value, ['TxnLineID', 'TxnLineId']),
                    'applied_amount' => $this->decimalField($value, ['PaymentAmount', 'AppliedAmount', 'Amount', 'CreditApplied']),
                    'currency' => $this->currencyField($value),
                    'source_reference' => $value,
                ]);
            }

            foreach ($value as $child) {
                $this->stageLinkNodes($item, $relationshipType, $child);
            }

            return;
        }

        foreach ($value as $child) {
            $this->stageLinkNodes($item, $relationshipType, $child);
        }
    }

    private function saveProgress(AccountingImportBatch $batch, int $recordsRead): void
    {
        $batch->forceFill(['checkpoint' => ['file_sha256' => $batch->file_sha256, 'records_read' => $recordsRead]])->save();
    }

    private function stringField(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key]) && trim((string) $data[$key]) !== '') {
                return trim((string) $data[$key]);
            }
        }

        return null;
    }

    private function dateField(array $data, array $keys): ?string
    {
        $date = $this->stringField($data, $keys);
        if ($date === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private function currencyField(array $data): ?string
    {
        $currency = $this->stringField($data, ['currency_code', 'CurrencyCode']);

        return $currency !== null && preg_match('/^[A-Za-z]{3}$/', $currency) === 1 ? strtoupper($currency) : null;
    }

    private function decimalField(array $data, array $keys): ?string
    {
        $value = $this->stringField($data, $keys);
        if ($value === null || preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $value) !== 1) {
            return null;
        }

        return $value;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($child): mixed => $this->canonicalize($child), $value);
        }

        ksort($value);

        foreach ($value as $key => $child) {
            $value[$key] = $this->canonicalize($child);
        }

        return $value;
    }
}
