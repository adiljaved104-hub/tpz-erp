<?php

namespace App\Services\Orders;

use App\Enums\InventoryPermission;
use App\Enums\OrderPermission;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\OrderAuthorization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OrderUpgradeReadService
{
    public function __construct(
        private readonly OrderAuthorization $orders,
        private readonly InventoryAuthorization $inventory,
    ) {}

    public function forOrder(Order $order, User $user): Builder
    {
        $this->orders->authorize($user, OrderPermission::View, $order);

        $query = DB::table('order_item_upgrade_selections as upgrade_selection')
            ->join('order_items as upgrade_item', 'upgrade_item.id', '=', 'upgrade_selection.order_item_id')
            ->where('upgrade_item.order_id', $order->id)
            ->select([
                'upgrade_selection.id', 'upgrade_selection.order_item_id', 'upgrade_item.line_number',
                'upgrade_item.sku', 'upgrade_item.product_name', 'upgrade_selection.configuration_snapshot',
            ]);

        if ($this->orders->allows($user, OrderPermission::ViewSellingPrice)) {
            $query->addSelect('upgrade_selection.suggested_selling_addon_snapshot');
        }

        if ($this->inventory->allows($user, InventoryPermission::ViewFinancials)) {
            $query->leftJoin('order_upgrade_executions as upgrade_execution', 'upgrade_execution.order_item_upgrade_selection_id', '=', 'upgrade_selection.id')
                ->addSelect([
                    'upgrade_selection.labour_cost_snapshot', 'upgrade_selection.recovery_snapshot',
                    'upgrade_execution.base_cogs', 'upgrade_execution.installed_component_cost',
                    'upgrade_execution.recovery_credit', 'upgrade_execution.labour_cost',
                    'upgrade_execution.final_configured_cogs',
                ]);
        }

        return $query->orderBy('upgrade_item.line_number');
    }
}
