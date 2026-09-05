<?php

namespace App\DTOs\Suppliers;

final readonly class UpdateSupplierData
{
    public function __construct(
        public string $name,
        public ?string $contactPerson = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $address = null,
        public ?string $vatNumber = null,
        public ?string $notes = null,
    ) {}
}
