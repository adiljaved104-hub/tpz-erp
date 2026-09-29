<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceIncidentService;
use App\Services\Marketplace\MarketplaceMonitoringSettingsService;
use App\Services\Marketplace\MarketplaceOperationsSummaryService;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\NotificationRuleService;
use Illuminate\Console\Command;

class SendMarketplaceOperationsSummary extends Command
{
    protected $signature = 'marketplace:send-summary {--scheduled}';

    protected $description = 'Send the scheduled Owner/Admin marketplace operations summary';

    public function handle(MarketplaceOperationsSummaryService $summary, MarketplaceIncidentService $incidents, MarketplaceMonitoringSettingsService $settings, CriticalAlertDispatcher $alerts, NotificationRuleService $rules): int
    {
        if ($this->option('scheduled') && ! $settings->summaryDueNow()) {
            return self::SUCCESS;
        }
        if (! $rules->channelEnabled('marketplace.daily_summary', 'email')) {
            return self::SUCCESS;
        }
        $data = $summary->summary();
        $details = [
            'Listings Monitored' => $data['listings_monitored'], 'Featured Offer Held' => $data['featured_offer_held'],
            'Featured Offer Lost' => $data['featured_offer_lost'], 'Stock Exposure' => $data['active_stock_exposure'],
            'Unacknowledged' => $data['unacknowledged'], 'Escalated' => $data['escalated'], 'Source Failures' => $data['source_failures'],
        ];
        $slot = now()->format('Y-m-d-H:i');
        foreach ($incidents->escalationRecipients() as $recipient) {
            $alerts->send($recipient, 'marketplace.daily_summary', 'marketplace-summary:'.$slot, [
                'category' => 'marketplace',
                'event' => 'marketplace.daily_summary',
                'title' => 'Marketplace Operations Summary',
                'message' => 'Daily operational monitoring summary.',
                'reference' => $slot,
                'status' => 'Summary',
            ], 'Marketplace Operations Daily Summary', '/admin/marketplace-operations', $details);
        }

        return self::SUCCESS;
    }
}
