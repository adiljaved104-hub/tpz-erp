<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Actions\Suppliers\UpdateSupplier;
use App\DTOs\Suppliers\UpdateSupplierData;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Supplier;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditSupplier extends EditRecord
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Supplier $record */
        return app(UpdateSupplier::class)->handle($record, new UpdateSupplierData(
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
