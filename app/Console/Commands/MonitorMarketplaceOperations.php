<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceIncidentReminderService;
use App\Services\Marketplace\MarketplaceMonitoringService;
use Illuminate\Console\Command;

class MonitorMarketplaceOperations extends Command
{
    protected $signature = 'marketplace:monitor {--limit=}';

    protected $description = 'Read marketplace observations and reconcile operational incidents';

    public function handle(MarketplaceMonitoringService $monitor, MarketplaceIncidentReminderService $reminders): int
    {
        $result = $monitor->run($this->option('limit') === null ? null : max(1, (int) $this->option('limit')));
        $notifications = $reminders->evaluate();
        $this->info("Checked {$result['checked']} listing(s); {$result['failed']} safe failure(s); {$notifications['reminders']} reminder(s); {$notifications['escalations']} escalation(s).");

        return self::SUCCESS;
    }
}
