<?php

namespace App\Services\Purchases;

use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Database\Eloquent\Builder;

class PurchaseReadService
{
    public function __construct(private readonly PurchaseAuthorization $authorization) {}

    public function purchases(User $actor): Builder
    {
        $this->authorization->authorize($actor, PurchasePermission::View);
        $fields = [
            'id', 'reference', 'supplier_id', 'warehouse_id', 'supplier_invoice_number',
            'supplier_invoice_date', 'purchase_date', 'expected_delivery_date', 'currency', 'status',
            'external_accounting_reference', 'entry_type', 'handled_by_employee_id',
            'notes', 'created_by_user_id', 'approved_by_user_id', 'approved_at', 'self_approved',
            'cancelled_by_user_id', 'cancelled_at', 'cancellation_reason', 'closed_by_user_id',
            'closed_at', 'closure_reason', 'created_at', 'updated_at',
        ];

        if ($this->authorization->allows($actor, PurchasePermission::ViewFinancials)) {
            array_push($fields, 'subtotal', 'discount_total', 'net_before_vat', 'shipping_total',
                'shipping_vat_rate', 'shipping_vat_amount', 'other_charges_total', 'other_charges_vat_rate',
                'other_charges_vat_amount', 'vat_total', 'grand_total');
        }

        return Purchase::query()->select($fields);
    }
}
