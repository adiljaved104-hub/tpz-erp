<?php

namespace App\Exports;

use App\DTOs\Reports\ReportResult;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithFreezePane;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TabularReportExport implements FromArray, ShouldAutoSize, WithColumnFormatting, WithFreezePane, WithStyles
{
    public function __construct(private readonly ReportResult $report) {}

    public function array(): array
    {
        return [
            [$this->report->title],
            ['Generated', now()->format('Y-m-d H:i:s T')],
            ['Filters', $this->filterSummary()],
            [],
            array_column($this->report->columns, 'label'),
            ...$this->report->rows->map(fn (array $row): array => array_map(
                fn (array $column): mixed => $this->spreadsheetValue($row[$column['key']] ?? null, $column['type'] ?? null),
                $this->report->columns,
            ))->all(),
        ];
    }

    public function freezePane(): string
    {
        return 'A6';
    }

    public function columnFormats(): array
    {
        $formats = [];
        foreach ($this->report->columns as $index => $column) {
            if (in_array(($column['type'] ?? null), ['money', 'money_aed', 'money_pkr'], true)) {
                $currency = ($column['type'] ?? null) === 'money_pkr' ? 'PKR' : 'AED';
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = '"'.$currency.'" #,##0.00';
            } elseif (($column['type'] ?? null) === 'number') {
                $formats[Coordinate::stringFromColumnIndex($index + 1)] = NumberFormat::FORMAT_NUMBER;
            }
        }

        return $formats;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 14]],
            5 => ['font' => ['bold' => true], 'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'E5E7EB']]],
        ];
    }

    private function filterSummary(): string
    {
        return collect($this->report->filters)->filter(fn ($value): bool => filled($value))->map(fn ($value, string $key): string => str($key)->replace('_', ' ')->title().': '.$value)->implode(' · ');
    }

    private function spreadsheetValue(mixed $value, ?string $type): mixed
    {
        if ($value === null || $value === '') {
            return $value;
        }

        return match ($type) {
            'money', 'money_aed', 'money_pkr' => (float) $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            default => $value,
        };
    }
}
