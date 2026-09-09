<?php

namespace App\Enums;

enum QuotationPermission: string
{
    case View = 'quotation.view';
    case Create = 'quotation.create';
    case Update = 'quotation.update';
    case ViewAll = 'quotation.view_all';
    case Send = 'quotation.send';
    case Accept = 'quotation.accept';
    case Reject = 'quotation.reject';
    case ConvertOrder = 'quotation.convert_order';
    case ConvertInvoice = 'quotation.convert_invoice';
    case Cancel = 'quotation.cancel';
    case Export = 'quotation.export';
    case SourceInventory = 'quotation.source_inventory';
    case ViewSourceCost = 'quotation.view_source_cost';
}
