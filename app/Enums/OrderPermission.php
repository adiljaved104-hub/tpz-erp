<?php

namespace App\Enums;

enum OrderPermission: string
{
    case View = 'order.view';
    case Create = 'order.create';
    case UpdateDraft = 'order.update_draft';
    case Confirm = 'order.confirm';
    case Reserve = 'order.reserve';
    case Process = 'order.process';
    case Fulfill = 'order.fulfill';
    case Cancel = 'order.cancel';
    case ViewSellingPrice = 'order.view_selling_price';
    case EditSellingPrice = 'order.edit_selling_price';
    case ViewCost = 'order.view_cost';
    case ViewProfit = 'order.view_profit';
    case Export = 'order.export';
}
