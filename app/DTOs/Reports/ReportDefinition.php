<?php

namespace App\DTOs\Reports;

use App\Models\User;
use Closure;

final readonly class ReportDefinition
{
    /**
     * @param  array<int, string>  $filters
     * @param  array<int, string>  $formats
     * @param  array<int, mixed>  $handlerArguments
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $group,
        public string $handler,
        public ?string $handlerMethod,
        public bool $period,
        public array $filters,
        public array $formats,
        public int $order,
        public bool $financialSensitive,
        private Closure $viewAuthorization,
        private Closure $exportAuthorization,
        public array $handlerArguments = [],
        public array $pdfColumns = [],
    ) {}

    public function canView(User $user): bool
    {
        return (bool) ($this->viewAuthorization)($user);
    }

    public function canExport(User $user, string $format): bool
    {
        return in_array($format, $this->formats, true) && (bool) ($this->exportAuthorization)($user);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'group' => $this->group,
            'handler' => $this->handler,
            'handler_method' => $this->handlerMethod,
            'handler_arguments' => $this->handlerArguments,
            'period' => $this->period,
            'filters' => $this->filters,
            'formats' => $this->formats,
            'order' => $this->order,
            'financial_sensitive' => $this->financialSensitive,
            'pdf_columns' => $this->pdfColumns,
        ];
    }
}
