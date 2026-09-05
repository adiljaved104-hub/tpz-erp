<?php

namespace App\Services\Reports\Providers;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use App\Enums\OfficeFinancePermission;
use App\Models\User;
use App\Services\Authorization\OfficeFinanceAuthorization;
use App\Services\Reports\Handlers\OfficeFinanceReportHandler;

class OfficeFinanceReportProvider implements ReportProvider
{
    public function __construct(private readonly OfficeFinanceAuthorization $authorization) {}

    public function definitions(): iterable
    {
        return [
            $this->definition('office_finance.cashbook', 'Pakistan Office Cashbook', ['status', 'category', 'employee', 'office_account'], OfficeFinancePermission::View, 830),
            $this->definition('office_finance.expenses', 'Pakistan Office Expenses (PKR)', ['status', 'category', 'employee', 'office_account'], OfficeFinancePermission::ViewBalances, 831),
            $this->definition('office_finance.funding', 'Funding Received', ['status', 'office_account'], OfficeFinancePermission::ViewFunding, 832),
            $this->definition('office_finance.loans_outstanding', 'Employee Loans Outstanding', ['employee'], OfficeFinancePermission::ViewLoans, 833),
            $this->definition('office_finance.loan_history', 'Employee Loan History', ['status', 'employee', 'office_account'], OfficeFinancePermission::ViewLoans, 834),
            $this->definition('office_finance.quickbooks', 'QuickBooks Handoff', ['status', 'category', 'employee', 'office_account'], OfficeFinancePermission::View, 835),
            $this->definition('office_finance.summary', 'Office Finance Summary', ['status', 'office_account'], OfficeFinancePermission::ViewBalances, 836),
        ];
    }

    private function definition(string $key, string $title, array $filters, OfficeFinancePermission $permission, int $order): ReportDefinition
    {
        return new ReportDefinition(
            key: $key, title: $title, group: 'Finance', handler: OfficeFinanceReportHandler::class,
            handlerMethod: null, period: true, filters: $filters, formats: ['xlsx', 'csv', 'pdf'], order: $order,
            financialSensitive: true,
            viewAuthorization: fn (User $user): bool => $this->authorization->allows($user, $permission),
            exportAuthorization: fn (User $user): bool => $this->authorization->allows($user, $permission) && $this->authorization->allows($user, OfficeFinancePermission::Export),
            pdfColumns: match ($key) {
                'office_finance.loans_outstanding' => ['reference', 'employee_reference', 'employee', 'loan_date', 'original_loan', 'repaid', 'outstanding', 'status'],
                'office_finance.summary' => ['type', 'entries', 'money_in', 'money_out'],
                default => ['date', 'reference', 'type', 'category', 'description', 'account', 'employee', 'money_in', 'money_out', 'status'],
            },
        );
    }
}
