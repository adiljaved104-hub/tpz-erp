<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOrderEvent;
use App\Services\Mobile\NotificationTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends MobileController
{
    public function incident(Request $request, MarketplaceOperationIncident $incident): JsonResponse
    {
        $this->authorizeTarget($request, 'marketplace_operation_incident', $incident->id, 'marketplace-operations/incidents');
        $incident->loadMissing(['product.brandRelation', 'platform', 'account', 'responsibleEmployee', 'responsibleTeam']);

        $title = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST
            ? 'Featured Offer Lost'
            : 'Marketplace Stock Exposure';

        return response()->json(['data' => [
            'id' => $incident->id,
            'title' => $title,
            'subtitle' => collect([$incident->product?->sku, $incident->product?->name])->filter()->implode(' · '),
            'meta' => collect([$incident->platform?->name, $incident->account?->name])->filter()->implode(' · '),
            'status' => $incident->resolved_at === null ? 'open' : 'resolved',
            'fields' => [
                'incident_type' => str($incident->incident_type)->headline()->toString(),
                'product' => $incident->product?->name,
                'sku' => $incident->product?->sku,
                'brand' => $incident->product?->displayBrandName(),
                'platform' => $incident->platform?->name,
                'account' => $incident->account?->name,
                'responsible_employee' => $incident->responsibleEmployee?->name,
                'responsible_team' => $incident->responsibleTeam?->name,
                'exposed_listing_count' => $incident->exposed_listing_count,
                'usable_quantity' => $incident->usable_quantity,
                'opened_at' => $incident->opened_at?->toIso8601String(),
                'escalated_at' => $incident->escalated_at?->toIso8601String(),
                'regained_at' => $incident->regained_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ],
        ]]);
    }

    public function order(Request $request, MarketplaceOrderEvent $event): JsonResponse
    {
        $this->authorizeTarget($request, 'marketplace_order_event', $event->id, 'marketplace-operations/orders');
        $event->loadMissing(['account.platform', 'items.product']);

        return response()->json(['data' => [
            'id' => $event->id,
            'title' => 'Marketplace Order '.$event->external_order_id,
            'subtitle' => collect([$event->account?->platform?->name, $event->account?->name])->filter()->implode(' · '),
            'meta' => $event->detected_at?->toIso8601String(),
            'status' => 'new',
            'fields' => [
                'external_order_id' => $event->external_order_id,
                'platform' => $event->account?->platform?->name,
                'account' => $event->account?->name,
                'source' => $event->source,
                'detected_at' => $event->detected_at?->toIso8601String(),
            ],
            'items' => $event->items->map(fn ($item): array => [
                'id' => $item->id,
                'sku' => $item->external_sku,
                'product' => $item->product?->name ?? $item->title,
                'condition' => $item->product_condition,
                'quantity' => $item->quantity,
            ])->values(),
        ]]);
    }

    private function authorizeTarget(Request $request, string $type, int $id, string $module): void
    {
        $target = app(NotificationTarget::class)->resolve($request->user(), [
            'target_type' => $type,
            'target_id' => $id,
        ]);
        abort_unless($target === ['module' => $module, 'id' => $id], 404);
    }
}
