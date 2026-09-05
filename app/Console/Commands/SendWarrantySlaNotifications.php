<?php

namespace App\Console\Commands;

use App\Enums\WarrantyRepairStatus;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\WarrantyRepair;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\CriticalAlertRecipientResolver;
use App\Services\Notifications\NotificationRuleService;
use App\Services\Operations\BackgroundServiceHealth;
use App\Services\ServiceCases\WarrantySlaService;
use Illuminate\Console\Command;

class SendWarrantySlaNotifications extends Command
{
    protected $signature = 'warranty:send-sla-notifications';

    protected $description = 'Send idempotent due-soon and overdue Warranty SLA alerts';

    public function handle(WarrantySlaService $sla, CriticalAlertRecipientResolver $recipients, CriticalAlertDispatcher $alerts, NotificationRuleService $rules, BackgroundServiceHealth $health): int
    {
        $sent = 0;
        $dueSoonDays = $rules->threshold('warranty.due_soon', WarrantySlaService::DUE_SOON_DAYS);
        WarrantyRepair::query()
            ->whereNotIn('status', [WarrantyRepairStatus::Completed->value, WarrantyRepairStatus::Cancelled->value])
            ->with(['assignedTo.employee', 'product'])
            ->chunkById(100, function ($cases) use ($sla, $recipients, $alerts, $dueSoonDays, &$sent): void {
                foreach ($cases as $case) {
                    $days = $sla->daysLeft($case);
                    if ($days > $dueSoonDays) {
                        continue;
                    }
                    $overdue = $days < 0;
                    $type = $overdue ? 'warranty.sla_overdue' : 'warranty.sla_due_soon';
                    $title = $overdue ? 'Warranty SLA Overdue' : 'Warranty SLA Due Soon';
                    $reason = $overdue ? abs($days).' day(s) overdue.' : 'Due in '.$days.' day(s).';

                    foreach ($recipients->warranty($case, $type) as $recipient) {
                        $sent += (int) $alerts->send($recipient, $type, $case->id.':'.$sla->dueAt($case)->toJSON(), [
                            'category' => 'warranty', 'event' => $type, 'title' => $title,
                            'message' => $case->reference.' · '.$reason, 'reference' => $case->reference,
                            'status' => $case->status->getLabel(), 'target_type' => 'warranty_repair', 'target_id' => $case->id,
                        ], '[ERP] '.$title.' — '.$case->reference, WarrantyRepairResource::getUrl('view', ['record' => $case]));
                    }
                }
            });

        $this->info("Sent {$sent} Warranty SLA alert(s).");
        $health->record(BackgroundServiceHealth::WARRANTY_EVALUATOR_SUCCESS);

        return self::SUCCESS;
    }
}
