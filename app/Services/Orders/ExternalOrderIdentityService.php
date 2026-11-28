<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class ExternalOrderIdentityService
{
    public function hash(?int $platformId, ?string $externalOrderNumber): ?string
    {
        $normalized = $this->normalize($externalOrderNumber);

        if ($platformId === null || $normalized === null) {
            return null;
        }

        return hash('sha256', $platformId.'|'.$normalized);
    }

    public function normalize(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_strtolower($value);
    }

    public function warning(?int $platformId, ?string $value, User $actor, ?int $ignoreOrderId = null): ?string
    {
        $hash = $this->hash($platformId, $value);
        if ($hash === null || ! Order::query()->where('external_identity_hash', $hash)->when($ignoreOrderId, fn ($q) => $q->whereKeyNot($ignoreOrderId))->exists()) {
            return null;
        }

        return app(OrderReferenceSearchService::class)->duplicateValidationMessage($actor, $hash, $ignoreOrderId);
    }

    public function duplicateNotice(?int $platformId, ?string $value, User $actor, ?int $ignoreOrderId = null): ?array
    {
        $warning = $this->warning($platformId, $value, $actor, $ignoreOrderId);
        if ($warning === null) {
            return null;
        }
        try {
            $order = app(OrderReferenceSearchService::class)->query($actor)
                ->where('external_identity_hash', $this->hash($platformId, $value))
                ->when($ignoreOrderId, fn ($q) => $q->whereKeyNot($ignoreOrderId))->first();
        } catch (AuthorizationException) {
            $order = null;
        }

        return ['message' => $warning, 'order' => $order];
    }
}
