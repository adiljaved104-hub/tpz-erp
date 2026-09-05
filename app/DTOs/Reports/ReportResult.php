<?php

namespace App\DTOs\Reports;

use Illuminate\Support\Collection;

final readonly class ReportResult
{
    /**
     * @param  array<int, array{key:string,label:string,type?:string}>  $columns
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, int|float|string>  $summary
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public Collection $rows,
        public array $summary,
        public array $filters,
        public int $totalRows,
    ) {}
}
