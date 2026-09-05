<?php

namespace App\DTOs\ProductIntelligence;

final readonly class ProductMatchReason
{
    public function __construct(
        public string $field,
        public string $status,
        public string $message,
        public mixed $searched = null,
        public mixed $candidate = null,
    ) {}

    public function isConflict(): bool
    {
        return $this->status === 'conflict';
    }
}
