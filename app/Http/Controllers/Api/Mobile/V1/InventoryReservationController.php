<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Inventory\ReleaseInventoryReservation;
use App\DTOs\Inventory\ReleaseReservationData;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryReservationController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $authorization = app(InventoryAuthorization::class);
        abort_unless($authorization->allows($request->user(), InventoryPermission::View), 403);
        $query = InventoryReservation::query()->with([
            'product:id,name,sku',
            'warehouse:id,name,code',
            'inventory:id,product_id,warehouse_id',
            'orderItem.order:id,reference',
        ])->whereIn(
            'product_inventory_id',
            app(ResponsibilityProductScopeService::class)->inventoryIds($request->user()),
        )->orderByDesc('reserved_at')->orderByDesc('id');

        $term = trim((string) $request->query('q', ''));
        if ($term !== '') {
            $like = '%'.$term.'%';
            $query->where(fn ($reservations) => $reservations->where('reference', 'like', $like)
                ->orWhereHas('product', fn ($products) => $products->where('sku', 'like', $like)->orWhere('name', 'like', $like))
                ->orWhereHas('warehouse', fn ($warehouses) => $warehouses->where('code', 'like', $like)->orWhere('name', 'like', $like)));
            $request->merge(['q' => null]);
        }

        return $this->page(
            $request,
            $query,
            ['reference'],
            fn (InventoryReservation $reservation): array => $this->present($request, $reservation),
        );
    }

    public function show(Request $request, int $reservation): JsonResponse
    {
        return response()->json(['data' => $this->present($request, $this->readable($request, $reservation), true)]);
    }

    public function release(Request $request, int $reservation): JsonResponse
    {
        $reservation = $this->readable($request, $reservation);
        $data = $request->validate([
            'reason' => 'required|string|max:2000',
            'idempotency_key' => 'required|uuid',
        ]);
        app(ReleaseInventoryReservation::class)->handle(
            $reservation,
            new ReleaseReservationData($data['reason'], $data['idempotency_key']),
            $request->user(),
        );

        return $this->show($request, $reservation->id);
    }

    private function readable(Request $request, int $id): InventoryReservation
    {
        $reservation = InventoryReservation::query()->with([
            'product:id,name,sku',
            'warehouse:id,name,code',
            'inventory:id,product_id,warehouse_id',
            'orderItem.order:id,reference',
        ])->findOrFail($id);
        abort_unless(app(InventoryAuthorization::class)->allows(
            $request->user(),
            InventoryPermission::View,
            $reservation->inventory,
        ), 403);

        return $reservation;
    }

    private function present(Request $request, InventoryReservation $reservation, bool $detail = false): array
    {
        $data = [
            'id' => $reservation->id,
            'title' => $reservation->reference.' · '.$reservation->product?->sku,
            'subtitle' => $reservation->product?->name,
            'meta' => $reservation->warehouse?->name.' · Qty '.$reservation->quantity,
            'status' => $reservation->status->value,
        ];
        if (! $detail) {
            return $data;
        }
        $actions = [];
        if ($reservation->status === InventoryReservationStatus::Active
            && app(InventoryAuthorization::class)->allows(
                $request->user(),
                InventoryPermission::ReleaseReservation,
                $reservation->inventory,
            )) {
            $actions[] = $this->action('release', 'Release reservation', [
                $this->field('reason', 'Release reason', 'multiline', true),
            ]);
        }

        return [...$data, 'fields' => [
            'reference' => $reservation->reference,
            'product' => $reservation->product?->name,
            'sku' => $reservation->product?->sku,
            'warehouse' => $reservation->warehouse?->name,
            'quantity' => $reservation->quantity,
            'reservation_kind' => $reservation->reservation_kind?->value,
            'order_reference' => $reservation->orderItem?->order?->reference,
            'reason' => $reservation->reason,
            'reserved_at' => $reservation->reserved_at?->format('Y-m-d H:i'),
            'released_at' => $reservation->released_at?->format('Y-m-d H:i'),
            'fulfilled_at' => $reservation->fulfilled_at?->format('Y-m-d H:i'),
        ], 'actions' => $actions];
    }
}
