<?php

namespace App\Enums;

enum CustomerReturnPermission: string
{
    case View = 'return.view';
    case Create = 'return.create';
    case Receive = 'return.receive';
    case Inspect = 'return.inspect';
    case Cancel = 'return.cancel';
    case RecordMarketplaceDisposition = 'return.record_marketplace_disposition';
    case ViewRefundAmount = 'return.view_refund_amount';
    case RecordRefund = 'return.record_refund';
}
