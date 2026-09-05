<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Actions\Suppliers\CreateSupplier as CreateSupplierAction;
use App\DTOs\Suppliers\CreateSupplierData;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateSupplier extends CreateRecord
{
    protected static string $resource = SupplierResource::class;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateSupplierAction::class)->handle(new CreateSupplierData(
            name: $data['name'],
            contactPerson: $data['contact_person'] ?? null,
            phone: $data['phone'] ?? null,
            email: $data['email'] ?? null,
            address: $data['address'] ?? null,
            vatNumber: $data['vat_number'] ?? null,
            notes: $data['notes'] ?? null,
        ), auth()->user());
    }
}
