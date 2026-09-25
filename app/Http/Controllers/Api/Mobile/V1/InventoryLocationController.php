<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\InventoryLocationPermission;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryLocationAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLocationController extends MobileController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return $this->page(
            $request,
            Warehouse::query()->with('marketplacePlatform:id,name')
                ->select(['id', 'code', 'name', 'location_type', 'marketplace_platform_id', 'fulfillment_tag', 'address', 'status'])
                ->orderBy('name'),
            ['code', 'name', 'fulfillment_tag'],
            fn (Warehouse $location): array => $this->present($location),
        );
    }

    public function show(Request $request, int $location): JsonResponse
    {
        $this->authorizeView($request);
        $location = Warehouse::query()->with('marketplacePlatform:id,name')
            ->select(['id', 'code', 'name', 'location_type', 'marketplace_platform_id', 'fulfillment_tag', 'address', 'status'])
            ->findOrFail($location);

        return response()->json(['data' => $this->present($location, true)]);
    }

    private function authorizeView(Request $request): void
    {
        abort_unless(
            app(InventoryLocationAuthorization::class)->allows($request->user(), InventoryLocationPermission::View),
            403,
        );
    }

    private function present(Warehouse $location, bool $detail = false): array
    {
        $type = $location->location_type->value;
        $data = [
            'id' => $location->id,
            'title' => $location->code.' · '.$location->name,
            'subtitle' => str($type)->replace('_', ' ')->headline()->toString(),
            'meta' => $location->marketplacePlatform?->name,
            'status' => $location->status ? 'active' : 'inactive',
        ];

        return $detail ? [...$data, 'fields' => [
            'code' => $location->code,
            'name' => $location->name,
            'location_type' => $type,
            'marketplace_platform' => $location->marketplacePlatform?->name,
            'fulfillment_tag' => $location->fulfillment_tag,
            'address' => $location->address,
        ], 'actions' => []] : $data;
    }
}
