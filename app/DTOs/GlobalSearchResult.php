<?php

namespace App\DTOs;

final readonly class GlobalSearchResult
{
    public function __construct(
        public string $group,
        public string $label,
        public string $description,
        public string $url,
        public string $icon = 'heroicon-o-magnifying-glass',
    ) {}
}
