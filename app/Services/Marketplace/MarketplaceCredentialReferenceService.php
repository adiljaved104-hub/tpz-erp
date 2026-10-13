<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceConnection;
use App\Models\MarketplaceCredentialVault;
use Throwable;

class MarketplaceCredentialReferenceService
{
    /** @return array<string, mixed> */
    public function credentials(MarketplaceConnection $connection): array
    {
        $reference = $connection->getRawOriginal('credential_reference');
        if (! is_string($reference) || ! preg_match('/\A[a-z0-9][a-z0-9._-]{0,190}\z/i', $reference)) {
            return [];
        }

        try {
            $vault = MarketplaceCredentialVault::query()->where('reference', $reference)->first();
            if ($vault !== null) {
                $credentials = $vault->encrypted_payload;

                return is_array($credentials) ? $credentials : [];
            }
        } catch (Throwable) {
            return [];
        }

        return match ($reference) {
            'amazon_default' => (array) config('marketplace_monitoring.amazon', []),
            default => (array) config('marketplace_credentials.references.'.$reference, []),
        };
    }
}
