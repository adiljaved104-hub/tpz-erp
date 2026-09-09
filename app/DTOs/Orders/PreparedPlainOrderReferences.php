<?php

namespace App\DTOs\Orders;

/** References for a plain-product Order are allocated before its business transaction. */
final readonly class PreparedPlainOrderReferences
{
    public function __construct(
        public string $reference,
        public array $reservationReferences,
        public array $movementReferences,
        public array $postingKeys,
        public array $lineKeys,
    ) {}
}
