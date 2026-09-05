<?php

namespace App\Services\Orders;

use App\Enums\EmployeeRole;
use App\Enums\WebSalesPermission;
use App\Models\Order;
use App\Models\OrderFulfillmentItem;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use App\Support\ConfiguredCogs;
use Illuminate\Database\Eloquent\Builder;

class WebSalesReadService
{
    public function __construct(
        private readonly WebSalesAuthorization $authorization,
        private readonly OrderResponsibilityScopeService $responsibilities,
    ) {}

    public function scoped(User $user): Builder
    {
        $this->authorization->authorize($user, WebSalesPermission::View);

        $query = $this->responsibilities->applyOrders(Order::query()->webSales(), $user);

        if ($this->authorization->allows($user, WebSalesPermission::ViewAll)) {
            return $query;
        }

        $employee = $user->employee;
        if ($employee === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($employee->role === EmployeeRole::Manager && $employee->team_id !== null) {
            return $query->whereHas('handledBy', fn (Builder $employees): Builder => $employees->where('team_id', $employee->team_id));
        }

        return $query->where('created_by_user_id', $user->id);
    }

    public function orders(User $user): Builder
    {
        $fields = [
            'orders.id', 'orders.reference', 'orders.source', 'orders.web_sales_channel', 'orders.status',
            'orders.warehouse_id', 'orders.customer_name', 'orders.customer_phone', 'orders.delivery_type',
            'orders.courier_name', 'orders.tracking_number', 'orders.order_date', 'orders.handled_by_employee_id',
            'orders.notes', 'orders.created_by_user_id', 'orders.reserved_at', 'orders.cancelled_at',
            'orders.delivered_at', 'orders.cancellation_reason', 'orders.created_at', 'orders.updated_at',
        ];

        if ($this->authorization->allows($user, WebSalesPermission::ViewRevenue)) {
            $fields[] = 'orders.grand_total';
        }

        $query = $this->scoped($user)->select($fields)
            ->with(['warehouse:id,name,code', 'handledBy:id,name,team_id'])
            ->withSum('items as units', 'ordered_quantity')
            ->with(['items:id,order_id,sku,product_name,ordered_quantity']);

        if ($this->authorization->allows($user, WebSalesPermission::ViewCost)) {
            $query->addSelect(['cogs_total' => OrderFulfillmentItem::query()
                ->selectRaw('COALESCE(SUM('.ConfiguredCogs::sql('order_fulfillment_items').'), 0)')
                ->whereIn('order_item_id', fn ($items) => $items->select('id')->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))]);
        }

        if ($this->authorization->allows($user, WebSalesPermission::ViewGrossProfit)) {
            $query->addSelect(['gross_profit' => OrderFulfillmentItem::query()
                ->selectRaw('orders.grand_total - COALESCE(SUM('.ConfiguredCogs::sql('order_fulfillment_items').'), 0)')
                ->whereIn('order_item_id', fn ($items) => $items->select('id')->from('order_items')->whereColumn('order_items.order_id', 'orders.id'))]);
        }

        return $query;
    }

    public function canAccess(User $user, Order $order): bool
    {
        return $this->scoped($user)->whereKey($order)->exists();
    }
}
