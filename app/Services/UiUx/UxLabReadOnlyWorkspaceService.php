<?php

namespace App\Services\UiUx;

use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WebSalesOrders\WebSalesOrderResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use BackedEnum;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * UX Lab bridge to the EXISTING authorized ERP read models.
 *
 * All data is fetched using the same resource-scoped queries as operational
 * pages; no writes or new permissions are introduced by the UX Lab.
 */
final class UxLabReadOnlyWorkspaceService
{
    private const MAX_PREVIEW_ROWS = 6;

    /**
     * @return array<int, array{label:string,url:string,rows:array<int, array{title:string,detail:string,status:string,url:string}>}>
     */
    public function sections(string $workspace, string $screen, string $search = ''): array
    {
        $resources = match ($workspace) {
            'home' => [OrderResource::class, WebSalesOrderResource::class, StockRequestResource::class, StockTransferResource::class],
            'sales' => match ($screen) {
                'orders' => [OrderResource::class],
                'web' => [WebSalesOrderResource::class],
                'quotations' => [QuotationResource::class],
                default => [OrderResource::class, WebSalesOrderResource::class, QuotationResource::class],
            },
            'inventory' => match ($screen) {
                'requests' => [StockRequestResource::class],
                'transfers' => [StockTransferResource::class],
                'adjustments' => [],
                default => [ProductResource::class, StockRequestResource::class, StockTransferResource::class],
            },
            'purchasing' => [PurchaseResource::class],
            'returns' => [CustomerReturnResource::class],
            default => [],
        };

        $sections = [];
        foreach ($resources as $resource) {
            if (! $resource::canViewAny()) {
                continue;
            }

            $rows = $resource::getEloquentQuery()
                ->orderByDesc('created_at')
                ->limit(self::MAX_PREVIEW_ROWS)
                ->get()
                ->filter(fn (Model $record): bool => $resource::canView($record))
                ->map(fn (Model $record): array => $this->summarize($resource, $record))
                ->values()
                ->all();

            if (trim($search) !== '') {
                $needle = mb_strtolower(trim($search));
                $rows = array_values(array_filter($rows, static fn (array $row): bool => str_contains(
                    mb_strtolower($row['title'].' '.$row['detail'].' '.$row['status']),
                    $needle,
                )));
            }

            $sections[] = [
                'label' => $this->label($resource),
                'url' => $resource::getUrl('index'),
                'rows' => $rows,
            ];
        }

        return $sections;
    }

    private function summarize(string $resource, Model $record): array
    {
        $title = match ($resource) {
            ProductResource::class => $record->sku.' · '.$record->name,
            WebSalesOrderResource::class => $record->reference.' · '.($record->customer_name ?: 'Web sale'),
            default => $record->reference ?: 'Record #'.$record->getKey(),
        };

        $detail = match ($resource) {
            OrderResource::class => 'Sales order · '.($record->external_order_number ?: 'Internal order'),
            WebSalesOrderResource::class => 'Web Sales · '.($record->web_sales_channel instanceof BackedEnum ? $record->web_sales_channel->value : ($record->web_sales_channel ?? '')),
            QuotationResource::class => 'Quotation',
            ProductResource::class => 'SKU '.$record->sku,
            StockRequestResource::class => 'Stock Request',
            StockTransferResource::class => 'Stock Transfer',
            PurchaseResource::class => 'Purchase',
            CustomerReturnResource::class => 'Customer Return',
            default => 'ERP record',
        };

        $status = $record->status ?? null;
        $statusLabel = $status instanceof BackedEnum ? $status->value : (string) ($status ?? 'Open');

        return [
            'title' => (string) $title,
            'detail' => $detail,
            'status' => str_replace('_', ' ', ucfirst($statusLabel)),
            'url' => $resource::getUrl('view', ['record' => $record->getKey()]),
        ];
    }

    private function label(string $resource): string
    {
        return match ($resource) {
            OrderResource::class => 'Sales Orders',
            WebSalesOrderResource::class => 'Web Sales Orders',
            QuotationResource::class => 'Quotations',
            ProductResource::class => 'Products',
            StockRequestResource::class => 'Stock Requests',
            StockTransferResource::class => 'Stock Transfers',
            PurchaseResource::class => 'Purchases',
            CustomerReturnResource::class => 'Customer Returns',
            default => $resource::getPluralModelLabel(),
        };
    }
}
