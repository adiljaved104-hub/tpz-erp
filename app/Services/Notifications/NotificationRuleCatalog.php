<?php

namespace App\Services\Notifications;

use App\Services\Dashboard\DashboardInventoryIntelligenceService;
use App\Services\ServiceCases\WarrantySlaService;

class NotificationRuleCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'task.assigned' => $this->rule('Task Assigned', 'Tasks', 'task_assignee'),
            'task.reassigned' => $this->rule('Task Reassigned', 'Tasks', 'task_assignee'),
            'task.awaiting_confirmation' => $this->rule('Task Awaiting Confirmation', 'Tasks', 'existing_business_routing', ['existing_business_routing', 'task_creator', 'owner_admin_fallback']),
            'task.overdue' => $this->rule('Task Overdue', 'Tasks', 'task_assignee'),
            'task.due_soon' => $this->rule('Task Due Soon', 'Tasks', 'task_assignee', null, null, null, [], false),
            'task.returned' => $this->rule('Task Returned', 'Tasks', 'task_assignee', null, null, null, [], false),
            'task.completion_confirmed' => $this->rule('Task Completion Confirmed', 'Tasks', 'task_assignee', null, null, null, [], false),
            'warranty.due_soon' => $this->rule('Warranty SLA Due Soon', 'Warranty', 'existing_business_routing', ['existing_business_routing', 'assigned_employee', 'owner_admin_fallback'], WarrantySlaService::DUE_SOON_DAYS, 'days'),
            'warranty.overdue' => $this->rule('Warranty SLA Overdue', 'Warranty', 'existing_business_routing', ['existing_business_routing', 'assigned_employee', 'owner_admin_fallback']),
            'inventory.low_stock' => $this->rule('Low Stock', 'Inventory', 'existing_business_routing', ['existing_business_routing', 'owner_admin_fallback'], null, null, ['threshold_source' => 'inventory']),
            'inventory.out_of_stock' => $this->rule('Out of Stock', 'Inventory', 'existing_business_routing', ['existing_business_routing', 'owner_admin_fallback']),
            'claim.needs_filing' => $this->rule('Claim Needs Filing', 'Claims / Returns', 'existing_business_routing', ['existing_business_routing', 'assigned_employee', 'owner_admin_fallback']),
            'return.awaiting_qc' => $this->rule('Return Awaiting QC', 'Claims / Returns', 'existing_business_routing', ['existing_business_routing', 'owner_admin_fallback']),
            'hr.warning_issued' => $this->rule('Warning Issued', 'HR', 'warned_employee'),
            'hr.notice_issued' => $this->rule('Notice Issued', 'HR', 'materialized_notice_recipients'),
            'leave.submitted' => $this->rule('Leave Request Submitted', 'HR', 'existing_business_routing', null, null, null, [], false),
            'leave.approved' => $this->rule('Leave Approved', 'HR', 'assigned_employee', null, null, null, [], false),
            'leave.rejected' => $this->rule('Leave Rejected', 'HR', 'assigned_employee', null, null, null, [], false),
        ];
    }

    public function canonicalKey(string $key): string
    {
        return match ($key) {
            'warranty.sla_due_soon' => 'warranty.due_soon',
            'warranty.sla_overdue' => 'warranty.overdue',
            'warning.issued' => 'hr.warning_issued',
            'notice.published' => 'hr.notice_issued',
            default => $key,
        };
    }

    /** @return array<string, string> */
    public function recipientOptions(string $eventKey): array
    {
        $definition = $this->all()[$this->canonicalKey($eventKey)] ?? null;
        $strategies = $definition['recipient_options'] ?? [];
        $labels = [
            'task_assignee' => 'Task Assignee',
            'task_creator' => 'Task Creator',
            'assigned_employee' => 'Assigned Employee',
            'warned_employee' => 'Warned Employee',
            'materialized_notice_recipients' => 'Published Notice Recipients',
            'owner_admin_fallback' => 'Owner / Admin Fallback',
            'existing_business_routing' => 'Existing Business Routing',
        ];

        return collect($strategies)->mapWithKeys(fn (string $strategy): array => [$strategy => $labels[$strategy]])->all();
    }

    public function inventoryLowStockThreshold(): int
    {
        return DashboardInventoryIntelligenceService::LOW_STOCK_THRESHOLD;
    }

    /** @return array<string, mixed> */
    private function rule(string $name, string $category, string $recipient, ?array $options = null, ?int $threshold = null, ?string $unit = null, array $configuration = [], bool $email = true): array
    {
        return [
            'name' => $name,
            'category' => $category,
            'enabled' => true,
            'in_app_enabled' => true,
            'email_enabled' => $email,
            'recipient_strategy' => $recipient,
            'recipient_options' => $options ?? [$recipient],
            'threshold_value' => $threshold,
            'threshold_unit' => $unit,
            'configuration' => $configuration,
        ];
    }
}
