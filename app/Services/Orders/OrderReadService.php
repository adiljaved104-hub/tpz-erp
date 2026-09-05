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

        if (! $this->responsibilities->requiresScope($user)) {
            return $query;
        }

        $employeeId = $user->employee?->id;

        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->whereRaw('EXISTS (SELECT 1 FROM order_items present_oi WHERE present_oi.order_id = orders.id)')
            ->whereRaw(<<<'SQL'
                NOT EXISTS (
                    SELECT 1
                    FROM order_items scoped_oi
                    JOIN products scoped_product ON scoped_product.id = scoped_oi.product_id
                    WHERE scoped_oi.order_id = orders.id
                      AND NOT EXISTS (
                        SELECT 1
                        FROM responsibility_assignments order_ra
                        WHERE order_ra.employee_id = ?
                          AND order_ra.status = 'active'
                          AND order_ra.ended_at IS NULL
                          AND (
                            NOT EXISTS (
                                SELECT 1 FROM responsibility_assignment_platforms ps
                                WHERE ps.assignment_id = order_ra.id
                            )
                            OR EXISTS (
                                SELECT 1 FROM responsibility_assignment_platforms ps
                                WHERE ps.assignment_id = order_ra.id
                                  AND ps.marketplace_platform_id = orders.marketplace_platform_id
                            )
                          )
                          AND (
                            EXISTS (
                                SELECT 1 FROM responsibility_assignment_products prs
                                WHERE prs.assignment_id = order_ra.id
                                  AND prs.product_id = scoped_oi.product_id
                            )
                            OR EXISTS (
                                SELECT 1 FROM responsibility_assignment_brands bs
                                WHERE bs.assignment_id = order_ra.id
                                  AND bs.product_brand_id = scoped_product.brand_id
                            )
                            OR EXISTS (
                                SELECT 1
                                FROM inventory_responsibility_quantities qs
                                JOIN product_inventories qpi ON qpi.id = qs.product_inventory_id
                                WHERE qs.assignment_id = order_ra.id
                                  AND qpi.product_id = scoped_oi.product_id
                                  AND qpi.warehouse_id = orders.warehouse_id
                            )
                            OR (
                                EXISTS (SELECT 1 FROM responsibility_assignment_platforms po WHERE po.assignment_id = order_ra.id)
                                AND NOT EXISTS (SELECT 1 FROM responsibility_assignment_products np WHERE np.assignment_id = order_ra.id)
                                AND NOT EXISTS (SELECT 1 FROM responsibility_assignment_brands nb WHERE nb.assignment_id = order_ra.id)
                                AND NOT EXISTS (SELECT 1 FROM inventory_responsibility_quantities nq WHERE nq.assignment_id = order_ra.id)
                            )
                          )
                      )
                )
                SQL, [$employeeId]);
    }
}
