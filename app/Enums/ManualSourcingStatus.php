<?php

namespace App\Enums;

enum ManualSourcingStatus: string
{
    case PendingSourcing = 'pending_sourcing';
    case PurchasePending = 'purchase_pending';
    case ReceiptPending = 'receipt_pending';
    case ReadyForAllocation = 'ready_for_allocation';
    case SupplierDirectFulfilled = 'supplier_direct_fulfilled';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';
}
