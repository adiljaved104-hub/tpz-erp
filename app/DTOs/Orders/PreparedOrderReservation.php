<?php

namespace App\DTOs\Orders;

/** References are allocated before the business transaction, including on retries. */
final readonly class PreparedOrderReservation
{
    public function __construct(
        public SaveAndReserveOrderData $data,
        public string $reference,
        public array $reservationReferences,
        public array $movementReferences,
        public array $postingKeys,
        public array $lineKeys,
        public array $upgradePlans,
    ) {}
}
