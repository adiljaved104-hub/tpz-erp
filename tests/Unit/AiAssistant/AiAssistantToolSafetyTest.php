<?php

namespace Tests\Unit\AiAssistant;

use App\Services\AiAssistant\ErpReportToolRegistry;
use PHPUnit\Framework\TestCase;

class AiAssistantToolSafetyTest extends TestCase
{
    public function test_registered_reporting_tools_are_only_read_only_reporting_functions(): void
    {
        $dependencies = [
            \Mockery::mock(\App\Services\Reports\ReportQueryService::class),
            \Mockery::mock(\App\Services\Authorization\InventoryAuthorization::class),
            \Mockery::mock(\App\Services\Authorization\ResponsibilityAuthorization::class),
            \Mockery::mock(\App\Services\Inventory\StockRequestService::class),
            \Mockery::mock(\App\Services\Responsibilities\ResponsibilityReadService::class),
            \Mockery::mock(\App\Services\Authorization\AiAssistantAuthorization::class),
            \Mockery::mock(\App\Services\AiAssistant\AiAssistantSettings::class),
        ];
        $registry = new ErpReportToolRegistry(...$dependencies);
        $names = array_column($registry->definitions(), 'name');
        self::assertSame(['sales_orders_report', 'web_sales_report', 'product_inventory_report', 'employee_inventory_allocations', 'stock_requests_report', 'customer_returns_report', 'customer_return_refunds_report'], $names);
        foreach ($registry->definitions() as $definition) {
            self::assertSame('function', $definition['type']);
            self::assertSame(false, $definition['parameters']['additionalProperties']);
        }
    }
}
