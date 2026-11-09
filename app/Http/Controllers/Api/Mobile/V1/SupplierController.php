<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Suppliers\CreateSupplier;
use App\Actions\Suppliers\SetSupplierStatus;
use App\Actions\Suppliers\UpdateSupplier;
use App\DTOs\Suppliers\ChangeSupplierStatusData;
use App\DTOs\Suppliers\CreateSupplierData;
use App\DTOs\Suppliers\UpdateSupplierData;
use App\Enums\PurchasePermission;
use App\Exceptions\SupplierOperationalUseException;
use App\Models\Supplier;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupplierController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::SupplierView);

        return $this->page(
            $request,
            Supplier::query()->select(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'status'])->orderBy('name'),
            ['name', 'contact_person', 'phone', 'email'],
            fn (Supplier $supplier): array => $this->present($request, $supplier),
        );
    }

    public function show(Request $request, int $supplier): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::SupplierView);
        $supplier = Supplier::query()
            ->select(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'vat_number', 'notes', 'status'])
            ->findOrFail($supplier);

        return response()->json(['data' => $this->present($request, $supplier, true)]);
    }

    public function options(Request $request): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::SupplierView);

        return response()->json(['data' => [
            'can_create' => $request->user()->can('create', Supplier::class),
            'fields' => $request->user()->can('create', Supplier::class) ? $this->editFields() : [],
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Supplier::class), 403);
        $supplier = app(CreateSupplier::class)->handle($this->createData($request), $request->user());

        return response()->json(['data' => $this->present($request, $supplier, true)], 201);
    }

    public function act(Request $request, int $supplier, string $action): JsonResponse
    {
        abort_unless($request->user()->can('create', Supplier::class), 403);
        $supplier = Supplier::query()->findOrFail($supplier);

        if ($action === 'update') {
            app(UpdateSupplier::class)->handle($supplier, $this->updateData($request), $request->user());
        } elseif (in_array($action, ['activate', 'deactivate'], true)) {
            $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

            try {
                app(SetSupplierStatus::class)->handle(
                    $supplier,
                    new ChangeSupplierStatusData($action === 'activate', $data['reason']),
                    $request->user(),
                );
            } catch (SupplierOperationalUseException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
        } else {
            abort(404);
        }

        return $this->show($request, $supplier->id);
    }

    private function present(Request $request, Supplier $supplier, bool $detail = false): array
    {
        $data = [
            'id' => $supplier->id,
            'title' => $supplier->name,
            'subtitle' => $supplier->contact_person,
            'meta' => $supplier->phone ?: $supplier->email,
            'status' => $supplier->status ? 'active' : 'inactive',
        ];

        if (! $detail) {
            return $data;
        }

        $fields = [
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
        ];
        $actions = [];
        if ($request->user()->can('update', $supplier)) {
            $actions[] = $this->action('update', 'Update supplier', $this->editFields($supplier));
            $actions[] = $this->action(
                $supplier->status ? 'deactivate' : 'activate',
                $supplier->status ? 'Deactivate supplier' : 'Activate supplier',
                [$this->field('reason', 'Reason', 'multiline', true)],
                $supplier->status,
                $supplier->status
                    ? 'Deactivation is blocked while the supplier is in operational use.'
                    : 'Confirm why this supplier is being reactivated.',
            );
        }

        return [...$data, 'fields' => $fields, 'field_schema' => $this->readFields($fields), 'actions' => $actions];
    }

    private function createData(Request $request): CreateSupplierData
    {
        $data = $this->validatedSupplier($request);

        return new CreateSupplierData(
            $data['name'], $data['contact_person'] ?? null, $data['phone'] ?? null,
            $data['email'] ?? null, $data['address'] ?? null, $data['vat_number'] ?? null,
            $data['notes'] ?? null,
        );
    }

    private function updateData(Request $request): UpdateSupplierData
    {
        $data = $this->validatedSupplier($request);

        return new UpdateSupplierData(
            $data['name'], $data['contact_person'] ?? null, $data['phone'] ?? null,
            $data['email'] ?? null, $data['address'] ?? null, $data['vat_number'] ?? null,
            $data['notes'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    private function validatedSupplier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:2000'],
            'vat_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    private function editFields(?Supplier $supplier = null): array
    {
        return [
            $this->field('name', 'Supplier name', 'text', true, $supplier?->name),
            $this->field('contact_person', 'Contact person', 'text', false, $supplier?->contact_person),
            $this->field('phone', 'Phone', 'phone', false, $supplier?->phone),
            $this->field('email', 'Email', 'email', false, $supplier?->email),
            $this->field('address', 'Address', 'multiline', false, $supplier?->address),
            $this->field('vat_number', 'VAT number', 'text', false, $supplier?->vat_number),
            $this->field('notes', 'Internal notes', 'multiline', false, $supplier?->notes),
        ];
    }
}
