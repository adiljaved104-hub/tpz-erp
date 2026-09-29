<?php

namespace App\Services\Marketplace;

use App\DTOs\Marketplace\MarketplaceObservation;
use App\Models\ProductMarketplaceListing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class MarketplaceMonitoringService
{
    public function __construct(private readonly MarketplaceMonitorManager $manager, private readonly MarketplaceObservationService $observations) {}

    /** @return array{checked:int,failed:int,skipped:int} */
    public function run(?int $limit = null): array
    {
        $settings = app(MarketplaceMonitoringSettingsService::class)->effective();
        if (! $settings['monitoring_enabled']) {
            return ['checked' => 0, 'failed' => 0, 'skipped' => 1];
        }
        $lock = Cache::lock('marketplace-operations-monitor', max(300, (int) $settings['monitoring_interval_minutes'] * 120));
        if (! $lock->get()) {
            return ['checked' => 0, 'failed' => 0, 'skipped' => 1];
        }
        $result = ['checked' => 0, 'failed' => 0, 'skipped' => 0];
        try {
            ProductMarketplaceListing::query()->where('monitor_enabled', true)
                ->where(fn ($query) => $query->whereNull('marketplace_account_id')->orWhereHas('account', fn ($account) => $account->where('enabled', true)))
                ->with(['product.brandRelation', 'product.categoryRelation', 'platform', 'account'])
                ->orderByRaw('CASE WHEN last_checked_at IS NULL THEN 0 ELSE 1 END')->orderBy('last_checked_at')->orderBy('id')
                ->limit($limit ?? (int) config('marketplace_monitoring.batch_size', 100))->get()
                ->each(function (ProductMarketplaceListing $listing) use (&$result): void {
                    try {
                        $this->observations->record($listing, $this->manager->observe($listing));
                        $result['checked']++;
                    } catch (Throwable) {
                        Log::warning('Marketplace monitoring source check failed safely.', [
                            'listing_id' => $listing->id,
                            'source' => $listing->monitor_source,
                        ]);
                        $this->observations->record($listing, MarketplaceObservation::unavailable($listing->id, $listing->marketplace_platform_id, $listing->monitor_source, 'source_error', 'Marketplace source check failed safely.'));
                        $result['failed']++;
                    }
                });
        } finally {
            $lock->release();
        }

        return $result;
    }
}
