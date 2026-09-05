<?php

namespace App\Actions\Suppliers;

use App\DTOs\Suppliers\UpdateSupplierData;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class UpdateSupplier
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(Supplier $supplier, UpdateSupplierData $data, User $actor): Supplier
    {
        if (! $actor->can('update', $supplier)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make([
            'name' => trim($data->name),
            'contact_person' => $this->nullable($data->contactPerson),
            'phone' => $this->nullable($data->phone),
            'email' => $this->nullable($data->email),
            'address' => $this->nullable($data->address),
            'vat_number' => $this->nullable($data->vatNumber),
            'notes' => $this->nullable($data->notes),
        ], [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'vat_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($supplier, $validated, $actor): Supplier {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplier->getKey());
            $supplier->fill($validated);
            $changedFields = array_keys($supplier->getDirty());
            $supplier->save();

            if ($changedFields !== []) {
                $this->activity->log('supplier.updated', $actor, $supplier, ['changed_fields' => $changedFields]);
            }

            return $supplier;
        });
    }

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
