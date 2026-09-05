<?php

namespace App\Enums;

enum PurchasePermission: string
{
    case View = 'purchase.view';
    case Create = 'purchase.create';
    case UpdateDraft = 'purchase.update_draft';
    case ViewFinancials = 'purchase.view_financials';
    case ViewCostHistory = 'purchase.view_cost_history';
    case Approve = 'purchase.approve';
    case Cancel = 'purchase.cancel';
    case Receive = 'purchase.receive';
    case Close = 'purchase.close';
    case ViewReceipts = 'purchase.view_receipts';
    case Export = 'purchase.export';
    case QuickReceive = 'purchase.quick_receive';
    case SupplierView = 'supplier.view';
    case SupplierManage = 'supplier.manage';
}
