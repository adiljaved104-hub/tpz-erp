<?php

namespace App\Actions\Suppliers;

use App\DTOs\Suppliers\CreateSupplierData;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CreateSupplier
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handle(CreateSupplierData $data, User $actor): Supplier
    {
        if (! $actor->can('create', Supplier::class)) {
            throw new AuthorizationException;
        }

        $validated = Validator::make($this->attributes($data), $this->rules())->validate();

        return DB::transaction(function () use ($validated, $actor): Supplier {
            $supplier = Supplier::query()->create([...$validated, 'status' => true]);
            $this->activity->log('supplier.created', $actor, $supplier, ['active' => true]);

            return $supplier;
        });
    }

    /** @return array<string, mixed> */
    private function attributes(CreateSupplierData $data): array
    {
        return [
            'name' => trim($data->name),
            'contact_person' => $this->nullable($data->contactPerson),
            'phone' => $this->nullable($data->phone),
            'email' => $this->nullable($data->email),
            'address' => $this->nullable($data->address),
            'vat_number' => $this->nullable($data->vatNumber),
            'notes' => $this->nullable($data->notes),
        ];
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'vat_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    private function nullable(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }
}
