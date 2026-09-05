<?php

namespace App\Services\ProductIntelligence;

use App\DTOs\ProductIntelligence\ParsedProductQuery;

class ProductQueryParser
{
    public function __construct(private readonly ProductQueryNormalizer $normalizer) {}

    /** @param array<string, mixed> $attributes @param list<string> $knownBrands */
    public function parse(string $query, array $attributes = [], array $knownBrands = []): ParsedProductQuery
    {
        $attributeText = collect(['brand', 'model', 'processor', 'ram', 'storage', 'graphics', 'screen_size'])
            ->map(fn (string $key): string => trim((string) ($attributes[$key] ?? '')))
            ->filter()->implode(' ');
        $normalized = $this->normalizer->normalize(trim($query.' '.$attributeText));
        $tokens = $this->normalizer->tokens($normalized);
        $brand = $this->brand($normalized, $attributes, $knownBrands);
        [$cpuFamily, $cpuModel] = $this->cpu($this->value($attributes, 'processor') ?? $normalized);
        [$gpuVendor, $gpuModel] = $this->gpu($this->value($attributes, 'graphics') ?? $normalized);
        $ramMb = $this->ram($normalized, $attributes, $cpuModel, $gpuModel);
        $storageGb = $this->storage($normalized, $attributes, $cpuModel, $gpuModel, $ramMb);
        $storageType = $this->storageType($this->value($attributes, 'storage') ?? $normalized);
        $screen = $this->screen($this->value($attributes, 'screen_size') ?? $normalized);
        $model = $this->model($normalized, $attributes, $brand, $cpuModel, $gpuModel);
        $identified = array_filter([$brand, $model, $cpuModel, $gpuModel, $ramMb, $storageGb, $screen], fn ($value): bool => $value !== null);
        $confidence = min(1, count($identified) / 6);

        return new ParsedProductQuery(
            raw: $query,
            normalized: $normalized,
            tokens: $tokens,
            brand: $brand,
            model: $model,
            cpuFamily: $cpuFamily,
            cpuModel: $cpuModel,
            gpuVendor: $gpuVendor,
            gpuModel: $gpuModel,
            ramMb: $ramMb,
            storageGb: $storageGb,
            storageType: $storageType,
            screenSizeInches: $screen,
            unparsedTokens: $this->unparsed($tokens, [$brand, $model, $cpuFamily, $cpuModel, $gpuVendor, $gpuModel, $storageType]),
            confidence: $confidence,
        );
    }

    /** @param array<string, mixed> $attributes @param list<string> $knownBrands */
    private function brand(string $normalized, array $attributes, array $knownBrands): ?string
    {
        if (($value = $this->value($attributes, 'brand')) !== null) {
            return $this->normalizer->normalize($value);
        }

        $brands = collect([...config('product_matching.known_brands', []), ...$knownBrands])
            ->map(fn ($brand): string => $this->normalizer->normalize((string) $brand))
            ->filter()->unique()->sortByDesc('length');

        return $brands->first(fn (string $brand): bool => preg_match('/(?:^|\s)'.preg_quote($brand, '/').'(?:\s|$)/u', $normalized) === 1);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function cpu(string $value): array
    {
        $value = $this->normalizer->normalize($value);

        if (preg_match('/\bcore\s+ultra\s+([3579])\s+([0-9]{3}\s*[a-z]{1,2})\b/u', $value, $match)) {
            return ['core ultra '.$match[1], strtoupper(str_replace(' ', '', $match[2]))];
        }
        if (preg_match('/\b(i[3579])\s*-?\s*([0-9]{4,5}\s*[a-z]{0,2}[0-9]?)\b/u', $value, $match)) {
            return [strtoupper($match[1]), strtoupper(str_replace(' ', '', $match[2]))];
        }
        if (preg_match('/\bryzen\s+([3579])\s+([0-9]{4}\s*[a-z]{0,2}[0-9]?)\b/u', $value, $match)) {
            return ['ryzen '.$match[1], strtoupper(str_replace(' ', '', $match[2]))];
        }
        if (preg_match('/\b(i[3579])\b/u', $value, $match)) {
            return [strtoupper($match[1]), null];
        }
        if (preg_match('/\bryzen\s+([3579])\b/u', $value, $match)) {
            return ['ryzen '.$match[1], null];
        }
        if (preg_match('/\b([0-9]{3}\s*[a-z]{1,2})\b/u', $value, $match)) {
            return [null, strtoupper(str_replace(' ', '', $match[1]))];
        }

        return [null, null];
    }

    /** @return array{0: ?string, 1: ?string} */
    private function gpu(string $value): array
    {
        $value = $this->normalizer->normalize($value);
        if (preg_match('/\b(rtx|gtx)\s+([0-9]{3,4}\s*(?:ti)?)\b/u', $value, $match)) {
            return ['nvidia', strtoupper($match[1].' '.str_replace(' ', '', $match[2]))];
        }
        if (preg_match('/\b(rx)\s+([0-9]{3,4}\s*(?:xt)?)\b/u', $value, $match)) {
            return ['amd', strtoupper($match[1].' '.str_replace(' ', '', $match[2]))];
        }

        return [null, null];
    }

    /** @param array<string, mixed> $attributes */
    private function ram(string $normalized, array $attributes, ?string $cpuModel, ?string $gpuModel): ?int
    {
        if (($value = $this->value($attributes, 'ram')) !== null) {
            return $this->normalizer->capacityMb($this->ensureCapacityUnit($value, 'gb'));
        }
        if (preg_match('/\b(?:ram|memory)\s*([0-9]+)\s*(tb|gb|mb)?\b|\b([0-9]+)\s*(tb|gb|mb)\s*(?:ram|memory|ddr[345]?)\b/u', $normalized, $match)) {
            $amount = ($match[1] ?? '') !== '' ? $match[1] : ($match[3] ?? '');
            $unit = ($match[2] ?? '') !== '' ? $match[2] : ($match[4] ?? '');

            return $this->normalizer->capacityMb($amount.' '.($unit ?: 'gb'));
        }
        if (preg_match_all('/\b([0-9]+)\s*(?:gb|g)\b/u', $normalized, $matches)) {
            foreach ($matches[1] ?? [] as $amount) {
                $amount = (int) $amount;
                if (in_array($amount, [4, 8, 12, 16, 24, 32, 48, 64, 96, 128], true)) {
                    return $amount * 1024;
                }
            }
        }

        foreach ($this->bareCapacityNumbers($normalized, $cpuModel, $gpuModel) as $number) {
            if (in_array($number, [4, 8, 12, 16, 24, 32, 48, 64, 96, 128], true)) {
                return $number * 1024;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $attributes */
    private function storage(string $normalized, array $attributes, ?string $cpuModel, ?string $gpuModel, ?int $ramMb): ?int
    {
        if (($value = $this->value($attributes, 'storage')) !== null) {
            return $this->normalizer->capacityGb($this->ensureCapacityUnit($value, 'gb'));
        }
        if (preg_match('/\b(?:ssd|nvme|hdd|storage)\s*([0-9]+(?:\.[0-9]+)?)\s*(tb|gb)?\b|\b([0-9]+(?:\.[0-9]+)?)\s*(tb|gb)\s*(?:ssd|nvme|hdd|storage)?\b/u', $normalized, $match)) {
            $amount = $match[1] !== '' ? $match[1] : $match[3];
            $unit = $match[2] !== '' ? $match[2] : $match[4];
            $capacity = $this->normalizer->capacityGb($amount.' '.($unit ?: 'gb'));
            if ($capacity !== null && ($ramMb === null || $capacity * 1024 !== $ramMb || $capacity >= 128)) {
                return $capacity;
            }
        }

        foreach (array_reverse($this->bareCapacityNumbers($normalized, $cpuModel, $gpuModel)) as $number) {
            if ($number >= 128) {
                return $number;
            }
        }

        return null;
    }

    private function storageType(string $value): ?string
    {
        $value = $this->normalizer->normalize($value);

        return match (true) {
            str_contains($value, 'nvme') => 'nvme',
            str_contains($value, 'ssd') => 'ssd',
            str_contains($value, 'hdd') => 'hdd',
            default => null,
        };
    }

    private function screen(string $value): ?float
    {
        $value = $this->normalizer->normalize($value);

        return preg_match('/\b(1[0-9](?:\.[0-9])?)\s*(?:inch|inches|in)?\b/u', $value, $match) ? (float) $match[1] : null;
    }

    /** @param array<string, mixed> $attributes */
    private function model(string $normalized, array $attributes, ?string $brand, ?string $cpuModel, ?string $gpuModel): ?string
    {
        if (($value = $this->value($attributes, 'model')) !== null) {
            return strtoupper(str_replace(' ', '', $this->normalizer->normalize($value)));
        }

        if (preg_match('/\b([a-z]{1,10}[0-9]{1,5})\s+gen\s+([0-9]{1,2})\b/u', $normalized, $match)) {
            return strtoupper($match[1].'GEN'.$match[2]);
        }
        if (preg_match('/\b([0-9]{3,4})\s+(g[0-9]{1,2})\b/u', $normalized, $match)) {
            return strtoupper($match[1].$match[2]);
        }
        if ($brand !== null && preg_match('/\b'.preg_quote($brand, '/').'\s+([0-9]{3,5})\b/u', $normalized, $match)) {
            return strtoupper($match[1]);
        }

        $excluded = array_filter([
            $brand === null ? null : str_replace(' ', '', $brand),
            $cpuModel === null ? null : strtolower($cpuModel),
            $gpuModel === null ? null : strtolower(str_replace(' ', '', $gpuModel)),
        ]);
        foreach ($this->normalizer->tokens($normalized) as $token) {
            $compact = str_replace(' ', '', $token);
            if (preg_match('/(?=.*[a-z])(?=.*[0-9])[a-z0-9-]{4,}/u', $compact)
                && preg_match('/^[0-9]+(?:gb|tb|mb|g)$/u', $compact) !== 1
                && ! in_array($compact, $excluded, true)) {
                return strtoupper($compact);
            }
        }

        return null;
    }

    /** @return list<int> */
    private function bareCapacityNumbers(string $normalized, ?string $cpuModel, ?string $gpuModel): array
    {
        preg_match_all('/\b([0-9]{1,4})(?:\s*(?:gb|g|tb))?\b/u', $normalized, $matches);
        $cpuNumber = $cpuModel === null ? null : (int) preg_replace('/\D+/', '', $cpuModel);
        $gpuNumber = $gpuModel === null ? null : (int) preg_replace('/\D+/', '', $gpuModel);

        return collect($matches[1] ?? [])->map(fn ($number): int => (int) $number)
            ->reject(fn (int $number): bool => $number === $cpuNumber || $number === $gpuNumber)
            ->unique()->values()->all();
    }

    /** @param list<string|null> $identified @param list<string> $tokens @return list<string> */
    private function unparsed(array $tokens, array $identified): array
    {
        $used = $this->normalizer->tokens(implode(' ', array_filter($identified)));

        return array_values(array_diff($tokens, $used));
    }

    /** @param array<string, mixed> $attributes */
    private function value(array $attributes, string $key): ?string
    {
        $value = trim((string) ($attributes[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    private function ensureCapacityUnit(string $value, string $unit): string
    {
        return preg_match('/\b(?:tb|gb|mb|g)\b/i', $value) ? $value : $value.' '.$unit;
    }
}
