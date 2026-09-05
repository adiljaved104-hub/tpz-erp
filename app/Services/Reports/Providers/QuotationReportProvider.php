<?php

namespace App\Services\Reports\Providers;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use App\Enums\QuotationPermission;
use App\Models\User;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\Reports\Handlers\QuotationReportHandler;

class QuotationReportProvider implements ReportProvider
{
    public function __construct(private readonly QuotationAuthorization $authorization) {}

    public function definitions(): iterable
    {
        return [
            $this->make('sales.quotations', 'Quotations', 700), $this->make('sales.quotations_by_status', 'Quotations by Status', 710),
            $this->make('sales.quotations_by_employee', 'Quotations by Employee', 720), $this->make('sales.accepted_converted_quotations', 'Accepted / Converted Quotations', 730),
            $this->make('sales.quotation_value_summary', 'Quotation Value Summary', 740),
        ];
    }

    private function make(string $key, string $title, int $order): ReportDefinition
    {
        return new ReportDefinition(
            key: $key,
            title: $title,
            group: 'Sales',
            handler: QuotationReportHandler::class,
            handlerMethod: null,
            period: true,
            filters: ['status', 'employee', 'product'],
            formats: ['xlsx', 'csv', 'pdf'],
            order: $order,
            financialSensitive: false,
            viewAuthorization: fn (User $user): bool => $this->authorization->allows($user, QuotationPermission::View),
            exportAuthorization: fn (User $user): bool => $this->authorization->allows($user, QuotationPermission::Export),
            pdfColumns: in_array($key, ['sales.quotations', 'sales.accepted_converted_quotations'], true)
                ? ['reference', 'document_type', 'quotation_date', 'valid_until', 'customer_name', 'status', 'grand_total', 'salesperson', 'order_reference', 'invoice_number']
                : match ($key) {
                    'sales.quotations_by_status' => ['status', 'quotation_count', 'quotation_value'],
                    'sales.quotations_by_employee' => ['employee', 'quotation_count', 'quotation_value'],
                    default => ['period', 'quotation_count', 'quotation_value'],
                },
        );
    }
}
