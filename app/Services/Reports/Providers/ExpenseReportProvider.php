<?php

namespace App\Services\Reports\Providers;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use App\Enums\ExpensePermission;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\Reports\Handlers\ExpenseReportHandler;

class ExpenseReportProvider implements ReportProvider
{
    public function __construct(private readonly ExpenseAuthorization $authorization) {}

    public function definitions(): iterable
    {
        return [
            $this->definition('finance.expenses', 'Business Expenses (AED)', ['status', 'category', 'cost_center', 'employee'], 800),
            $this->definition('finance.expenses_by_category', 'Business Expenses by Category (AED)', ['status', 'category', 'cost_center', 'employee'], 810),
            $this->definition('finance.expenses_by_cost_center', 'Business Expenses by Cost Center (AED)', ['status', 'category', 'cost_center', 'employee'], 820),
        ];
    }

    /** @param array<int, string> $filters */
    private function definition(string $key, string $title, array $filters, int $order): ReportDefinition
    {
        $view = fn (User $user): bool => $this->authorization->allows($user, ExpensePermission::View);

        return new ReportDefinition(
            key: $key,
            title: $title,
            group: 'Finance',
            handler: ExpenseReportHandler::class,
            handlerMethod: null,
            period: true,
            filters: $filters,
            formats: ['xlsx', 'csv', 'pdf'],
            order: $order,
            financialSensitive: true,
            viewAuthorization: $view,
            exportAuthorization: $view,
            pdfColumns: match ($key) {
                'finance.expenses' => ['expense_date', 'category', 'description', 'amount', 'cost_center', 'employee', 'status'],
                'finance.expenses_by_category' => ['category', 'expense_count', 'amount'],
                default => ['cost_center', 'expense_count', 'amount'],
            },
        );
    }
}
