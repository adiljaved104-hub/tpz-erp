<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\PurchasePermission;
use App\Models\Supplier;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::SupplierView);

        return $this->page(
            $request,
            Supplier::query()->select(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'status'])->orderBy('name'),
            ['name', 'contact_person', 'phone', 'email'],
            fn (Supplier $supplier): array => $this->present($supplier),
        );
    }

    public function show(Request $request, int $supplier): JsonResponse
    {
        app(PurchaseAuthorization::class)->authorize($request->user(), PurchasePermission::SupplierView);
        $supplier = Supplier::query()
            ->select(['id', 'name', 'contact_person', 'phone', 'email', 'address', 'status'])
            ->findOrFail($supplier);

        return response()->json(['data' => $this->present($supplier, true)]);
    }

    private function present(Supplier $supplier, bool $detail = false): array
    {
        $data = [
            'id' => $supplier->id,
            'title' => $supplier->name,
            'subtitle' => $supplier->contact_person,
            'meta' => $supplier->phone ?: $supplier->email,
            'status' => $supplier->status ? 'active' : 'inactive',
        ];

        return $detail ? [...$data, 'fields' => [
            'contact_person' => $supplier->contact_person,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
        ], 'actions' => []] : $data;
    }
}
