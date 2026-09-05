<?php

namespace App\Services\Reports;

use App\DTOs\Reports\ReportResult;
use App\Exports\TabularReportExport;
use App\Models\User;
use App\Services\ActivityLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportExportService
{
    public function __construct(
        private readonly ReportCatalog $catalog,
        private readonly ReportQueryService $queries,
        private readonly ActivityLogger $activity,
    ) {}

    /** @param array<string, mixed> $filters */
    public function export(User $user, string $report, string $format, array $filters): Response
    {
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);
        abort_unless($this->catalog->canExport($user, $report, $format), 403);
        $result = $this->utf8Safe($this->queries->run($user, $report, $filters, ReportQueryService::EXPORT_LIMIT + 1));
        $filename = str($report)->slug('-').'-'.now()->format('Y-m-d').'.'.$format;
        $response = match ($format) {
            'xlsx' => Excel::download(new TabularReportExport($result), $filename),
            'csv' => $this->csv($result, $filename),
            'pdf' => $this->pdf($user, $result, $filename),
        };
        $this->audit($user, $result, $format);

        return $response;
    }

    private function csv(ReportResult $report, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_column($report->columns, 'label'));
            foreach ($report->rows as $row) {
                fputcsv($output, array_map(fn (array $column): mixed => $row[$column['key']] ?? null, $report->columns));
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function pdf(User $user, ReportResult $report, string $filename): Response
    {
        $definition = $this->catalog->get($user, $report->key);
        $declared = $definition['pdf_columns'] ?? [];
        $available = collect($report->columns)->keyBy('key');
        $columns = $declared === []
            ? $report->columns
            : collect($declared)->map(fn (string $key): ?array => $available->get($key))->filter()->values()->all();
        $orientation = count($columns) > 7 ? 'landscape' : 'portrait';

        return Pdf::loadView('reports.pdf', ['report' => $report, 'columns' => $columns])
            ->setPaper('a4', $orientation)->download($filename);
    }

    private function audit(User $user, ReportResult $report, string $format): void
    {
        $filters = $report->filters;
        if (array_key_exists('cost_center', $filters)) {
            $filters['expense_scope'] = $filters['cost_center'];
            unset($filters['cost_center']);
        }

        $this->activity->log('report.exported', $user, properties: [
            'report' => $report->key,
            'format' => $format,
            'filters' => array_filter($filters, fn (mixed $value): bool => filled($value)),
            'row_count' => $report->totalRows,
            'columns' => array_column($report->columns, 'key'),
        ], description: 'Report exported');
    }

    private function utf8Safe(ReportResult $report): ReportResult
    {
        $columns = collect($report->columns)->map(fn (array $column): array => [
            ...$column,
            'label' => $this->safeText($column['label']),
        ])->all();
        $rows = $report->rows->map(fn (array $row): array => collect($row)
            ->map(fn (mixed $value): mixed => is_string($value) ? $this->safeText($value) : $value)
            ->all());
        $filters = collect($report->filters)->map(fn (mixed $value): mixed => is_string($value) ? $this->safeText($value) : $value)->all();

        return new ReportResult(
            $report->key,
            $this->safeText($report->title),
            $columns,
            $rows,
            $report->summary,
            $filters,
            $report->totalRows,
        );
    }

    private function safeText(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
