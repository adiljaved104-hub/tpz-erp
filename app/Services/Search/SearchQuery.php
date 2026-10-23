<?php

namespace App\Services\Search;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

final class SearchQuery
{
    public static function normalize(string $term): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $term) ?? $term);
    }

    /** @return array<int, string> */
    public static function tokens(string $term): array
    {
        $normalized = self::normalize($term);

        return $normalized === '' ? [] : array_slice(explode(' ', mb_strtolower($normalized)), 0, 12);
    }

    public static function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
    }

    /** @param array<int, string|null> $values */
    public static function matchesText(array $values, string $term): bool
    {
        $haystack = mb_strtolower(self::normalize(implode(' ', array_filter($values, fn (?string $value): bool => $value !== null))));

        foreach (self::tokens($term) as $token) {
            if (! str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $columns */
    public static function match(Builder $query, array $columns, string $term): Builder
    {
        $tokens = self::tokens($term);
        if ($tokens === [] || $columns === []) {
            return $query->whereRaw('1 = 0');
        }

        foreach ($tokens as $token) {
            $query->where(function (Builder $where) use ($columns, $token): void {
                foreach ($columns as $column) {
                    $where->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", [self::likePattern($token)]);
                }
            });
        }

        return $query;
    }

    /** @param array<int, string> $columns */
    public static function matchModel(EloquentBuilder $query, array $columns, string $term): EloquentBuilder
    {
        self::match($query->getQuery(), $columns, $term);

        return $query;
    }

    public static function rank(Builder $query, string $column, string $term): Builder
    {
        $term = mb_strtolower(self::normalize($term));
        $prefix = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';

        return $query->orderByRaw("CASE WHEN LOWER({$column}) = ? THEN 0 WHEN LOWER({$column}) LIKE ? ESCAPE '!' THEN 1 ELSE 2 END", [$term, $prefix]);
    }
}
