<?php

namespace App\Enums;

enum OfficeFinanceTransactionType: string
{
    case Expense = 'expense';
    case FundingReceived = 'funding_received';
    case EmployeeLoanGiven = 'employee_loan_given';
    case EmployeeLoanRepayment = 'employee_loan_repayment';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }

    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
