<?php

namespace App\Services\Responsibilities;

class MarketplacePlatformNormalizer
{
    public function displayName(string $value): string
    {
        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value) ?? '';

        return preg_replace('/\s+/u', ' ', $trimmed) ?? '';
    }

    public function normalizedName(string $value): string
    {
        return mb_strtolower($this->displayName($value), 'UTF-8');
    }

    public function code(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s-]+/u', '_', $value) ?? '';

        return trim($value, '_');
    }
}
