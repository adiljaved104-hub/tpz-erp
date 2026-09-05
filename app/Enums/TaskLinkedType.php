<?php

namespace App\Enums;

enum TaskLinkedType: string
{
    case Order = 'order';
    case CustomerReturn = 'customer_return';
    case SafetClaim = 'safet_claim';
    case WarrantyRepair = 'warranty_repair';
    case InternalRepair = 'internal_repair';
    case Complaint = 'complaint';
    case DamagedStockEvent = 'damaged_stock_event';
    case Product = 'product';
    case Purchase = 'purchase';
    case StockTransfer = 'stock_transfer';
    case Employee = 'employee';
}
