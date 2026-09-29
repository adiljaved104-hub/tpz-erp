<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceAccount;
use App\Models\MarketplaceOrderEvent;
use App\Models\Product;
use App\Models\ProductMarketplaceListing;
use App\Models\User;
use App\Services\Notifications\CriticalAlertDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceNewOrderService
{
    public function __construct(private readonly MarketplaceResponsibilityResolver $responsibilities, private readonly MarketplaceIncidentService $incidents, private readonly CriticalAlertDispatcher $alerts, private readonly MarketplaceMonitoringSettingsService $settings) {}

    /** @param array<int, array<string, mixed>> $items */
    public function record(MarketplaceAccount $account, string $externalOrderId, array $items, string $source, ?CarbonImmutable $detectedAt = null): MarketplaceOrderEvent
    {
        $externalOrderId = trim($externalOrderId);
        if ($externalOrderId === '' || $items === []) {
            throw ValidationException::withMessages(['external_order_id' => 'A marketplace order ID and at least one product are required.']);
        }

        [$event, $created] = DB::transaction(function () use ($account, $externalOrderId, $items, $source, $detectedAt): array {
            $existing = MarketplaceOrderEvent::query()->where('marketplace_account_id', $account->id)->where('external_order_id', $externalOrderId)->lockForUpdate()->first();
            if ($existing instanceof MarketplaceOrderEvent) {
                return [$existing->load('items'), false];
            }
            $event = MarketplaceOrderEvent::query()->create(['marketplace_account_id' => $account->id, 'external_order_id' => $externalOrderId, 'detected_at' => $detectedAt ?? CarbonImmutable::now(), 'source' => mb_substr(trim($source), 0, 40)]);
            foreach ($items as $index => $item) {
                $quantity = (int) ($item['quantity'] ?? 0);
                if ($quantity < 1) {
                    throw ValidationException::withMessages(["items.{$index}.quantity" => 'Order item quantity must be at least 1.']);
                }
                $listing = isset($item['listing_id']) ? ProductMarketplaceListing::query()->where('marketplace_account_id', $account->id)->find($item['listing_id']) : null;
                $productId = $listing?->product_id ?? ($item['product_id'] ?? null);
                $event->items()->create([
                    'product_id' => $productId,
                    'listing_id' => $listing?->id,
                    'external_sku' => $item['external_sku'] ?? $listing?->listing_sku,
                    'title' => $item['title'] ?? $listing?->listing_title,
                    'product_condition' => $item['product_condition'] ?? $account->product_condition,
                    'quantity' => $quantity,
                ]);
            }

            return [$event->load('items.product', 'items.listing'), true];
        });

        if ($created) {
            $this->notify($event->loadMissing('account.platform', 'items.product', 'items.listing'));
        }

        return $event;
    }

    private function notify(MarketplaceOrderEvent $event): void
    {
        $recipients = collect();
        foreach ($event->items as $item) {
            if (! $item->product instanceof Product) {
                continue;
            }
            $listing = $item->listing ?? new ProductMarketplaceListing(['product_id' => $item->product_id, 'marketplace_platform_id' => $event->account->marketplace_platform_id, 'marketplace_account_id' => $event->marketplace_account_id]);
            $listing->setRelation('product', $item->product)->setRelation('platform', $event->account->platform)->setRelation('account', $event->account);
            $recipients = $recipients->merge($this->responsibilities->assignments($listing)->pluck('employee.user'));
        }
        $recipients = $recipients->filter(fn ($user): bool => $user instanceof User)->unique('id')->values();
        if ($recipients->isEmpty()) {
            $recipients = $this->incidents->escalationRecipients();
        }
        foreach ($recipients as $recipient) {
            $this->alerts->send($recipient, 'marketplace.new_order', 'marketplace-order:'.$event->marketplace_account_id.':'.$event->external_order_id, [
                'category' => 'marketplace', 'event' => 'marketplace.new_order', 'title' => 'New Marketplace Order',
                'message' => $event->account->platform->name.' · '.$event->account->name.' received order '.$event->external_order_id.'.',
                'reference' => $event->external_order_id, 'status' => 'New', 'target_type' => 'marketplace_order_event', 'target_id' => $event->id,
            ], 'New Marketplace Order — '.$event->external_order_id, '/admin/marketplace-operations', [], $this->settings->effective()['event_channels']);
        }
    }
}
