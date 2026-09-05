<?php

namespace App\Enums;

enum StockTransferPermission: string
{
    case View = 'stock_transfer.view';
    case Create = 'stock_transfer.create';
    case Dispatch = 'stock_transfer.dispatch';
    case Receive = 'stock_transfer.receive';
    case Cancel = 'stock_transfer.cancel';
    case ViewCost = 'stock_transfer.view_cost';
}
