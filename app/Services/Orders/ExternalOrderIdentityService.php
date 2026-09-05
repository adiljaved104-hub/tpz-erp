<?php

namespace App\Services\Orders;

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
}
