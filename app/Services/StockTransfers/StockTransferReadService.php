<?php

namespace App\Services\StockTransfers;

use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class StockTransferReadService
{
    public function __construct(private readonly StockTransferResponsibilityScopeService $scope) {}

    public function query(User $user): Builder
    {
        $query = StockTransfer::query()->select('stock_transfers.*')->with([
            'sourceWarehouse:id,name,code,location_type,marketplace_platform_id,status',
            'destinationWarehouse:id,name,code,location_type,marketplace_platform_id,status',
            'handledBy:id,name,employee_id',
            'displayItems:id,stock_transfer_id,product_id,product_name,sku,quantity,dispatched_quantity,received_quantity,returned_quantity,lost_quantity',
        ]);

        if (! $this->scope->requiresScope($user)) {
            return $query;
        }
        $employeeId = $user->employee?->id;
        if ($employeeId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereRaw(<<<'SQL'
NOT EXISTS (
 SELECT 1 FROM stock_transfer_items sti JOIN products transfer_product ON transfer_product.id = sti.product_id
 WHERE sti.stock_transfer_id = stock_transfers.id AND NOT EXISTS (
  SELECT 1 FROM responsibility_assignments transfer_ra
  WHERE transfer_ra.employee_id = ? AND transfer_ra.status = 'active' AND transfer_ra.ended_at IS NULL
   AND (
    NOT EXISTS (SELECT 1 FROM responsibility_assignment_platforms rap0 WHERE rap0.assignment_id = transfer_ra.id)
    OR EXISTS (SELECT 1 FROM responsibility_assignment_platforms rap WHERE rap.assignment_id = transfer_ra.id
     AND rap.marketplace_platform_id = COALESCE(
      (SELECT marketplace_platform_id FROM warehouses WHERE id = stock_transfers.destination_warehouse_id AND location_type = 'marketplace_fulfilment'),
      (SELECT marketplace_platform_id FROM warehouses WHERE id = stock_transfers.source_warehouse_id AND location_type = 'marketplace_fulfilment')
     ))
   )
   AND (
    EXISTS (SELECT 1 FROM responsibility_assignment_products rp WHERE rp.assignment_id = transfer_ra.id AND rp.product_id = sti.product_id)
    OR EXISTS (SELECT 1 FROM responsibility_assignment_brands rb WHERE rb.assignment_id = transfer_ra.id AND rb.product_brand_id = transfer_product.brand_id)
    OR EXISTS (SELECT 1 FROM inventory_responsibility_quantities irq JOIN product_inventories qpi ON qpi.id = irq.product_inventory_id WHERE irq.assignment_id = transfer_ra.id AND qpi.product_id = sti.product_id AND qpi.warehouse_id = stock_transfers.source_warehouse_id)
    OR (EXISTS (SELECT 1 FROM responsibility_assignment_platforms rpo WHERE rpo.assignment_id = transfer_ra.id)
     AND NOT EXISTS (SELECT 1 FROM responsibility_assignment_products rpn WHERE rpn.assignment_id = transfer_ra.id)
     AND NOT EXISTS (SELECT 1 FROM responsibility_assignment_brands rbn WHERE rbn.assignment_id = transfer_ra.id)
     AND NOT EXISTS (SELECT 1 FROM inventory_responsibility_quantities rqn WHERE rqn.assignment_id = transfer_ra.id))
   )
 )
)
SQL, [$employeeId]);
    }
}
