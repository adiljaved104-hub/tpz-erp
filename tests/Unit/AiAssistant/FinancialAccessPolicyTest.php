<?php

namespace Tests\Unit\AiAssistant;

use App\Enums\EmployeeRole;
use App\Enums\WebSalesPermission;
use App\Models\Employee;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use PHPUnit\Framework\TestCase;

class FinancialAccessPolicyTest extends TestCase
{
    public function test_ai_does_not_grant_admin_owner_only_web_sales_cost_or_profit_access(): void
    {
        $user = new User;
        $user->setRelation('employee', new Employee(['role' => EmployeeRole::Admin, 'status' => true]));
        $authorization = new WebSalesAuthorization(\Mockery::mock(\App\Contracts\EmployeePermissionOverrideResolver::class), \Mockery::mock(\App\Services\Authorization\OrderAuthorization::class));
        self::assertFalse($authorization->roleDefault($user, WebSalesPermission::ViewCost));
        self::assertFalse($authorization->roleDefault($user, WebSalesPermission::ViewGrossProfit));
    }
}
