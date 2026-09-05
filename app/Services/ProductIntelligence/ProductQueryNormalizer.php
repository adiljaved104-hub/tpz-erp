<?php

namespace App\Services\ProductIntelligence;

use Illuminate\Support\Str;

class ProductQueryNormalizer
{
    public function normalize(?string $value): string
    {
        $value = Str::ascii(mb_strtolower(trim((string) $value), 'UTF-8'));
        $value = preg_replace('/[^a-z0-9.]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        foreach ((array) config('product_matching.aliases', []) as $pattern => $replacement) {
            $value = preg_replace($pattern, (string) $replacement, $value) ?? $value;
        }

        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    /** @return list<string> */
    public function tokens(?string $value): array
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? [] : array_values(array_unique(explode(' ', $normalized)));
    }

    public function comparable(?string $value): ?string
    {
        $normalized = $this->normalize($value);

        return $normalized === '' ? null : str_replace(' ', '', $normalized);
    }

    public function capacityMb(?string $value): ?int
    {
        return $this->capacity($value, 'mb');
    }

    public function capacityGb(?string $value): ?int
    {
        return $this->capacity($value, 'gb');
    }

    private function capacity(?string $value, string $target): ?int
    {
        $normalized = $this->normalize($value);
        if (! preg_match('/\b([0-9]+(?:\.[0-9]+)?)\s*(tb|gb|mb)\b/u', $normalized, $matches)) {
            return null;
        }

        $amount = (float) $matches[1];
        $unit = $matches[2];
        $mb = match ($unit) {
            'tb' => $amount * 1024 * 1024,
            'gb' => $amount * 1024,
            default => $amount,
        };

        return (int) round($target === 'gb' ? $mb / 1024 : $mb);
    }
}
