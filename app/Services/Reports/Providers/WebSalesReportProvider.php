<?php

namespace App\Services\Reports\Providers;

use App\Contracts\Reports\ReportProvider;
use App\DTOs\Reports\ReportDefinition;
use App\Enums\ExpensePermission;
use App\Enums\OrderPermission;
use App\Enums\WebSalesPermission;
use App\Models\User;
use App\Services\Authorization\ExpenseAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Reports\Handlers\WebSalesReportHandler;

class WebSalesReportProvider implements ReportProvider
{
    public function __construct(
        private readonly WebSalesAuthorization $webSales,
        private readonly OrderAuthorization $orders,
        private readonly ExpenseAuthorization $expenses,
    ) {}

    public function definitions(): iterable
    {
        return [
            $this->definition('web_sales.orders', 'Web Sales Orders', ['status', 'employee', 'channel'], 700),
            $this->definition('web_sales.performance', 'Web Sales Performance', [], 710),
            $this->definition('web_sales.by_employee', 'Web Sales by Employee', ['employee'], 720),
            $this->definition('web_sales.by_channel', 'Web Sales by Channel', ['channel'], 730),
            $this->definition('web_sales.profitability', 'Web Sales Profitability', [], 740, true, true),
        ];
    }

    /** @param array<int, string> $filters */
    private function definition(string $key, string $title, array $filters, int $order, bool $profitability = false, bool $financial = false): ReportDefinition
    {
        $view = fn (User $user): bool => $profitability ? $this->canViewProfitability($user) : $this->webSales->allows($user, WebSalesPermission::View);

        return new ReportDefinition(
            key: $key,
            title: $title,
            group: 'Web Sales',
            handler: WebSalesReportHandler::class,
            handlerMethod: null,
            period: true,
            filters: $filters,
            formats: ['xlsx', 'csv', 'pdf'],
            order: $order,
            financialSensitive: $financial,
            viewAuthorization: $view,
            exportAuthorization: fn (User $user): bool => $view($user) && $this->orders->allows($user, OrderPermission::Export),
            pdfColumns: match ($key) {
                'web_sales.orders' => ['reference', 'order_date', 'channel', 'status', 'handled_by', 'warehouse', 'units', 'revenue', 'gross_profit'],
                'web_sales.performance' => ['status', 'orders'],
                'web_sales.by_employee' => ['employee', 'orders', 'units', 'revenue', 'gross_profit'],
                'web_sales.by_channel' => ['channel', 'orders', 'units', 'revenue', 'gross_profit'],
                default => ['category', 'operating_expense'],
            },
        );
    }

    private function canViewProfitability(User $user): bool
    {
        return $this->webSales->allows($user, WebSalesPermission::View)
            && $this->webSales->allows($user, WebSalesPermission::ViewRevenue)
            && $this->webSales->allows($user, WebSalesPermission::ViewCost)
            && $this->webSales->allows($user, WebSalesPermission::ViewGrossProfit)
            && $this->expenses->allows($user, ExpensePermission::ViewAmount)
            && $this->expenses->allows($user, ExpensePermission::ViewNetProfit);
    }
}
