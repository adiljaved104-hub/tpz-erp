<?php

namespace Tests\Unit\AiAssistant;

use App\Enums\AiAssistantPermission;
use App\Models\User;
use App\Services\AiAssistant\AiAssistantSettings;
use App\Services\AiAssistant\ErpReportToolRegistry;
use App\Services\Authorization\AiAssistantAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Inventory\StockRequestService;
use App\Services\Reports\ReportQueryService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AiAssistantToolSafetyTest extends TestCase
{
    public function test_registered_reporting_tools_are_only_read_only_reporting_functions(): void
    {
        $dependencies = [
            \Mockery::mock(ReportQueryService::class),
            \Mockery::mock(InventoryAuthorization::class),
            \Mockery::mock(ResponsibilityAuthorization::class),
            \Mockery::mock(StockRequestService::class),
            \Mockery::mock(ResponsibilityReadService::class),
            \Mockery::mock(AiAssistantAuthorization::class),
            \Mockery::mock(AiAssistantSettings::class),
        ];
        $registry = new ErpReportToolRegistry(...$dependencies);
        $names = array_column($registry->definitions(), 'name');
        self::assertSame(['sales_orders_report', 'web_sales_report', 'product_inventory_report', 'employee_inventory_allocations', 'stock_requests_report', 'customer_returns_report', 'customer_return_refunds_report'], $names);
        foreach ($registry->definitions() as $definition) {
            self::assertSame('function', $definition['type']);
            self::assertSame(false, $definition['parameters']['additionalProperties']);
        }
    }

    public function test_each_reporting_tool_call_rechecks_assistant_authorization(): void
    {
        $authorization = \Mockery::mock(AiAssistantAuthorization::class);
        $authorization->shouldReceive('allows')->once()->with(\Mockery::type(User::class), AiAssistantPermission::Use)->andReturn(false);
        $settings = \Mockery::mock(AiAssistantSettings::class);
        $settings->shouldReceive('enabled')->once()->andReturn(true);
        $dependencies = [
            \Mockery::mock(ReportQueryService::class),
            \Mockery::mock(InventoryAuthorization::class),
            \Mockery::mock(ResponsibilityAuthorization::class),
            \Mockery::mock(StockRequestService::class),
            \Mockery::mock(ResponsibilityReadService::class),
            $authorization,
            $settings,
        ];
        $registry = new ErpReportToolRegistry(...$dependencies);

        try {
            $registry->call(new User, 'sales_orders_report', []);
            self::fail('An unauthorized user must not call a reporting tool.');
        } catch (HttpException $exception) {
            self::assertSame(403, $exception->getStatusCode());
        }
    }
}
