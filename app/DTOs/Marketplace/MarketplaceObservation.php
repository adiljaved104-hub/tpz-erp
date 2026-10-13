<?php

namespace App\DTOs\Marketplace;

use App\Enums\MarketplaceObservationState;
use Carbon\CarbonImmutable;

final readonly class MarketplaceObservation
{
    public function __construct(
        public int $listingId,
        public int $platformId,
        public MarketplaceObservationState $listingActive,
        public MarketplaceObservationState $featuredOfferHeld,
        public CarbonImmutable $observedAt,
        public string $source,
        public ?string $sourceStatus = null,
        public ?string $safeError = null,
        public ?int $accountId = null,
        public ?int $connectionId = null,
        public MarketplaceObservationState $stockAvailable = MarketplaceObservationState::Unknown,
    ) {}

    public static function unavailable(int $listingId, int $platformId, string $source, string $status, ?string $safeError = null, ?int $accountId = null, ?int $connectionId = null): self
    {
        return new self($listingId, $platformId, MarketplaceObservationState::Unknown, MarketplaceObservationState::Unknown, CarbonImmutable::now(), $source, $status, $safeError, $accountId, $connectionId);
    }
}
