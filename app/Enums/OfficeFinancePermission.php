<?php

namespace App\Enums;

enum OfficeFinancePermission: string
{
    case View = 'office_finance.view';
    case Create = 'office_finance.create';
    case Update = 'office_finance.update';
    case ViewAll = 'office_finance.view_all';
    case ViewBalances = 'office_finance.view_balances';
    case ViewFunding = 'office_finance.view_funding';
    case ViewLoans = 'office_finance.view_loans';
    case ManageLoans = 'office_finance.manage_loans';
    case Void = 'office_finance.void';
    case Export = 'office_finance.export';
}
