<?php

namespace App\Enums;

enum AccountingAccountType: string
{
    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Income = 'income';
    case Cogs = 'cogs';
    case Expense = 'expense';
}
