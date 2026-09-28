<?php

namespace App\Console\Commands;

use App\Services\Marketplace\MarketplaceIncidentService;
use App\Services\Marketplace\MarketplaceOperationsSummaryService;
use App\Services\Notifications\CriticalAlertDispatcher;
use App\Services\Notifications\NotificationRuleService;
use Illuminate\Console\Command;

class SendMarketplaceOperationsSummary extends Command
{
    protected $signature = 'marketplace:send-summary';

    protected $description = 'Send the scheduled Owner/Admin marketplace operations summary';

    public function handle(MarketplaceOperationsSummaryService $summary, MarketplaceIncidentService $incidents, CriticalAlertDispatcher $alerts, NotificationRuleService $rules): int
    {
        if (! $rules->channelEnabled('marketplace.daily_summary', 'email')) {
            return self::SUCCESS;
        }
        $data = $summary->summary();
        $details = [
            'Listings Monitored' => $data['listings_monitored'], 'Featured Offer Held' => $data['featured_offer_held'],
            'Featured Offer Lost' => $data['featured_offer_lost'], 'Stock Exposure' => $data['active_stock_exposure'],
            'Unacknowledged' => $data['unacknowledged'], 'Escalated' => $data['escalated'], 'Source Failures' => $data['source_failures'],
        ];
        foreach ($incidents->escalationRecipients() as $recipient) {
            $alerts->send($recipient, 'marketplace.daily_summary', 'marketplace-summary:'.now()->toDateString(), [
                'category' => 'marketplace',
                'event' => 'marketplace.daily_summary',
                'title' => 'Marketplace Operations Summary',
                'message' => 'Daily operational monitoring summary.',
                'reference' => now()->toDateString(),
                'status' => 'Summary',
            ], 'Marketplace Operations Daily Summary', '/admin/marketplace-operations', $details);
        }

        return self::SUCCESS;
    }
}
