<?php

namespace App\Services\Search;

use Illuminate\Database\Query\Builder;

final class SearchQuery
{
    /** @param array<int, string> $columns */
    public static function match(Builder $query, array $columns, string $term): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));

        return $query->where(function (Builder $where) use ($columns, $escaped): void {
            foreach ($columns as $column) {
                $where->orWhereRaw("LOWER({$column}) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
            }
        });
    }

    public static function rank(Builder $query, string $column, string $term): Builder
    {
        $term = mb_strtolower($term);

        return $query->orderByRaw("CASE WHEN LOWER({$column}) = ? THEN 0 WHEN LOWER({$column}) LIKE ? THEN 1 ELSE 2 END", [$term, $term.'%']);
    }
}
