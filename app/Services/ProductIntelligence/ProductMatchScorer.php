<?php

namespace App\Services\ProductIntelligence;

use App\DTOs\ProductIntelligence\ParsedProductQuery;
use App\DTOs\ProductIntelligence\ProductMatchReason;
use App\Enums\ProductMatchClassification;
use App\Enums\ProductMatchContext;

class ProductMatchScorer
{
    /** @return array{score: int, classification: ProductMatchClassification, reasons: list<ProductMatchReason>} */
    public function score(ParsedProductQuery $searched, ParsedProductQuery $candidate, ProductMatchContext $context): array
    {
        $weights = (array) config('product_matching.weights');
        $penalties = (array) config('product_matching.conflict_penalties');
        $reasons = [];
        $earned = 0.0;
        $possible = 0.0;
        $conflictPenalty = 0.0;

        foreach ([
            'brand' => ['Brand', $searched->brand, $candidate->brand],
            'model' => ['Model', $searched->model, $candidate->model],
            'cpu_family' => ['CPU family', $searched->cpuFamily, $candidate->cpuFamily],
            'cpu_model' => ['CPU', $searched->cpuModel, $candidate->cpuModel],
            'gpu_model' => ['GPU', $searched->gpuModel, $candidate->gpuModel],
            'ram_mb' => ['RAM', $searched->ramMb, $candidate->ramMb],
            'storage_gb' => ['Storage', $searched->storageGb, $candidate->storageGb],
        ] as $field => [$label, $expected, $actual]) {
            if ($expected === null) {
                continue;
            }

            $weight = (float) ($weights[$field] ?? 0);
            $possible += $weight;
            if ($actual === null) {
                $reasons[] = new ProductMatchReason($field, 'unknown', "{$label} is not recorded on this Product.", $expected);

                continue;
            }

            if ($this->equal($field, $expected, $actual)) {
                $earned += $weight;
                $reasons[] = new ProductMatchReason($field, 'match', "{$label}: ".$this->display($field, $actual), $expected, $actual);
            } else {
                $conflictPenalty += (float) ($penalties[$field] ?? 20);
                $reasons[] = new ProductMatchReason(
                    $field,
                    'conflict',
                    "{$label} differs: searched ".$this->display($field, $expected).'; candidate '.$this->display($field, $actual),
                    $expected,
                    $actual,
                );
            }
        }

        $titleWeight = (float) ($weights['title_similarity'] ?? 10);
        $possible += $titleWeight;
        $similarity = $this->tokenSimilarity($searched->tokens, $candidate->tokens);
        $earned += $titleWeight * $similarity;
        if ($similarity >= 0.45) {
            $reasons[] = new ProductMatchReason('title', 'match', 'Product title/model wording is similar.');
        }

        $score = (int) round(max(0, min(100, ($possible > 0 ? ($earned / $possible) * 100 : 0) - $conflictPenalty)));
        $hasConflict = collect($reasons)->contains(fn (ProductMatchReason $reason): bool => $reason->isConflict());
        $classification = $this->classification($score, $hasConflict, $context);

        return compact('score', 'classification', 'reasons');
    }

    private function classification(int $score, bool $hasConflict, ProductMatchContext $context): ProductMatchClassification
    {
        if ($context === ProductMatchContext::ProductCreation && ! $hasConflict && $score >= 82) {
            return ProductMatchClassification::PossibleDuplicate;
        }
        if (! $hasConflict && $score >= 94) {
            return ProductMatchClassification::Exact;
        }
        if (! $hasConflict && $score >= 84) {
            return ProductMatchClassification::VeryHigh;
        }
        if (! $hasConflict && $score >= 68) {
            return ProductMatchClassification::High;
        }
        if ($score >= 32 || $hasConflict) {
            return ProductMatchClassification::Similar;
        }

        return ProductMatchClassification::LowConfidence;
    }

    /** @param list<string> $left @param list<string> $right */
    private function tokenSimilarity(array $left, array $right): float
    {
        $stop = ['gb', 'tb', 'mb', 'core', 'intel', 'nvidia', 'geforce', 'laptop', 'notebook'];
        $originalLeft = $left;
        $originalRight = $right;
        $left = array_values(array_diff($left, $stop));
        $right = array_values(array_diff($right, $stop));
        if ($left === []) {
            $left = $originalLeft;
            $right = $originalRight;
        }
        if ($left === [] || $right === []) {
            return 0;
        }

        $matched = 0.0;
        foreach ($left as $token) {
            if (in_array($token, $right, true)) {
                $matched += 1;

                continue;
            }
            if (mb_strlen($token) >= 2 && collect($right)->contains(fn (string $other): bool => str_contains($other, $token))) {
                $matched += 0.9;

                continue;
            }
            if (mb_strlen($token) >= 4 && collect($right)->contains(fn (string $other): bool => mb_strlen($other) >= 4 && levenshtein($token, $other) <= 1)) {
                $matched += 0.7;
            }
        }

        return min(1, $matched / count($left));
    }

    private function equal(string $field, mixed $expected, mixed $actual): bool
    {
        if (is_int($expected) || is_float($expected)) {
            return abs((float) $expected - (float) $actual) < 0.001;
        }

        $expected = mb_strtolower(str_replace([' ', '-'], '', (string) $expected));
        $actual = mb_strtolower(str_replace([' ', '-'], '', (string) $actual));

        if ($expected === $actual) {
            return true;
        }

        return $field === 'model'
            && min(mb_strlen($expected), mb_strlen($actual)) >= 4
            && (str_contains($expected, $actual) || str_contains($actual, $expected));
    }

    private function display(string $field, mixed $value): string
    {
        return match ($field) {
            'ram_mb' => ((int) $value / 1024).'GB',
            'storage_gb' => ((int) $value >= 1024 && (int) $value % 1024 === 0) ? ((int) $value / 1024).'TB' : ((int) $value).'GB',
            default => (string) $value,
        };
    }
}
