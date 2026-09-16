<?php

namespace App\Services\Orders;

use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use Illuminate\Database\Eloquent\Builder;

class OrderReadService
{
    public function __construct(
        private readonly OrderResponsibilityScopeService $responsibilities,
        private readonly OrderAuthorization $authorization,
    ) {}

    public function orders(User $user): Builder
    {
        $this->authorization->authorize($user, OrderPermission::View);
        $fields = [
            'id', 'reference', 'source', 'status', 'warehouse_id', 'marketplace_platform_id',
            'external_order_number', 'order_date', 'handled_by_employee_id', 'notes',
            'created_by_user_id', 'reserved_by_user_id', 'cancelled_by_user_id',
            'reserved_at', 'cancelled_at', 'cancellation_reason', 'created_at', 'updated_at',
        ];

        if ($this->authorization->allows($user, OrderPermission::ViewSellingPrice)) {
            array_push($fields, 'subtotal', 'discount_total', 'vat_total', 'grand_total');
        }

        $query = Order::query()->select($fields);

        return $this->responsibilities->applyOrders($query, $user);
    }
}
