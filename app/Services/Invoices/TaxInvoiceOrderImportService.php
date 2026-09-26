<?php

namespace App\Services\Invoices;

use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductTitleMode;
use App\Models\Order;
use App\Models\User;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Orders\OrderReferenceSearchService;
use App\Services\Products\ProductTitleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaxInvoiceOrderImportService
{
    public function __construct(
        private readonly OrderReferenceSearchService $references,
        private readonly OrderAuthorization $authorization,
        private readonly ProductTitleService $titles,
    ) {}

    /** @return array<int, string> */
    public function options(User $actor, string $search): array
    {
        if (! $this->authorization->allows($actor, OrderPermission::ViewSellingPrice)) {
            return [];
        }

        return $this->references->options($actor, $search, $this->importableStatuses(), 30);
    }

    public function label(User $actor, int $orderId): ?string
    {
        $order = $this->authorizedOrder($actor, $orderId);

        return $order === null ? null : $this->references->label($order);
    }

    /** @return array<string, mixed>|null */
    public function prefill(User $actor, int $orderId, ProductTitleMode|string $mode = ProductTitleMode::Auto): ?array
    {
        $order = $this->authorizedOrder($actor, $orderId);
        if ($order === null) {
            return null;
        }

        $mode = $mode instanceof ProductTitleMode ? $mode : ProductTitleMode::tryFrom($mode) ?? ProductTitleMode::Auto;
        $items = $order->items->map(function ($item) use ($order, $mode): array {
            $item->setRelation('order', $order);

            return [
                'source_order_item_id' => $item->id,
                'description' => $this->titles->forOrderItem($item, $mode),
                'quantity' => $item->ordered_quantity,
                'unit_price_including_vat' => bcdiv((string) $item->line_total, (string) $item->ordered_quantity, 2),
            ];
        })->all();

        return [
            'source_order_id' => $order->id,
            'order_reference' => $order->reference,
            'external_order_number' => $order->external_order_number,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'items' => $items,
        ];
    }

    public function authorizedOrder(User $actor, int $orderId): ?Order
    {
        if (! $this->authorization->allows($actor, OrderPermission::ViewSellingPrice)) {
            return null;
        }

        try {
            return $this->references->query($actor)
                ->select(['orders.id', 'orders.reference', 'orders.source', 'orders.web_sales_channel', 'orders.external_order_number', 'orders.marketplace_platform_id', 'orders.customer_name', 'orders.customer_phone', 'orders.status'])
                ->whereKey($orderId)
                ->where('orders.status', '!=', OrderStatus::Cancelled->value)
                ->with(['items' => fn (HasMany $items): HasMany => $items
                    ->select(['id', 'order_id', 'product_id', 'product_name', 'ordered_quantity', 'selling_price', 'line_total', 'line_number'])
                    ->with([
                        'upgradeSelection:id,order_item_id,configuration_snapshot',
                        'product:id,sku,name,brand,brand_id,category,category_id,model,processor,processor_class,processor_model,processor_generation,ram,storage,screen_size,graphics,color,touch_screen,is_convertible_360,accounting_title_override,website_title_override',
                        'product.brandRelation:id,name,status', 'product.categoryRelation:id,name,status',
                        'product.marketplaceListings:id,product_id,marketplace_platform_id,listing_title',
                    ])])
                ->first();
        } catch (AuthorizationException) {
            return null;
        }
    }

    /** @return list<OrderStatus> */
    private function importableStatuses(): array
    {
        return array_values(array_filter(OrderStatus::cases(), fn (OrderStatus $status): bool => $status !== OrderStatus::Cancelled));
    }
}
