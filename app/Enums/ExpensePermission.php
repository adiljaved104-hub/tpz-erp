<?php

namespace App\Enums;

enum ExpensePermission: string
{
    case View = 'expense.view';
    case Create = 'expense.create';
    case Update = 'expense.update';
    case ViewAll = 'expense.view_all';
    case ViewAmount = 'expense.view_amount';
    case DeleteOrVoid = 'expense.delete_or_void';
    case ViewNetProfit = 'expense.view_net_profit';
}
