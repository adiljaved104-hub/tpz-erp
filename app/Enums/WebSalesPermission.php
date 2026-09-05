<?php

namespace App\Enums;

enum WebSalesPermission: string
{
    case View = 'web_sales.view';
    case Create = 'web_sales.create';
    case Update = 'web_sales.update';
    case ViewAll = 'web_sales.view_all';
    case ViewRevenue = 'web_sales.view_revenue';
    case ViewCost = 'web_sales.view_cost';
    case ViewGrossProfit = 'web_sales.view_gross_profit';
    case Cancel = 'web_sales.cancel';
}
