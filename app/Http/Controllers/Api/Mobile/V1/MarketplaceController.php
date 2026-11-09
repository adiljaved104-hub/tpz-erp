<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\MarketplaceOperationsPermission;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOrderEvent;
use App\Models\User;
use App\Services\Authorization\MarketplaceOperationsAuthorization;
use App\Services\Marketplace\MarketplaceIncidentAcknowledgementService;
use App\Services\Responsibilities\ResponsibilityReadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MarketplaceController extends MobileController
{
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        $incidents = $this->incidentQuery($user);
        $orders = $this->orderQuery($user);
        $unacknowledged = (clone $incidents)->whereNull('resolved_at');
        $unacknowledged->whereHas('recipients', fn (Builder $query) => $query
            ->when(! $this->hasOversight($user), fn (Builder $recipient) => $recipient->where('user_id', $user->id))
            ->whereNull('acknowledged_at'));

        return response()->json(['data' => [
            'open_incidents' => (clone $incidents)->whereNull('resolved_at')->count(),
            'unacknowledged_incidents' => $unacknowledged->count(),
            'escalated_incidents' => (clone $incidents)->whereNull('resolved_at')->whereNotNull('escalated_at')->count(),
            'order_events' => (clone $orders)->count(),
            'responsibilities' => app(ResponsibilityReadService::class)->myPlatformResponsibilities($user)->values(),
            'oversight' => $this->hasOversight($user),
        ]]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:open,resolved,escalated,unacknowledged'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->incidentQuery($request->user())
            ->with(['product.brandRelation', 'platform', 'account', 'responsibleEmployee', 'responsibleTeam', 'recipients'])
            ->when(filled($data['q'] ?? null), function (Builder $query) use ($data): void {
                $term = '%'.trim($data['q']).'%';
                $query->where(fn (Builder $scope) => $scope
                    ->whereHas('product', fn (Builder $product) => $product->where('sku', 'like', $term)->orWhere('name', 'like', $term))
                    ->orWhereHas('platform', fn (Builder $platform) => $platform->where('name', 'like', $term))
                    ->orWhereHas('account', fn (Builder $account) => $account->where('name', 'like', $term)));
            })
            ->when(($data['status'] ?? null) === 'open', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->when(($data['status'] ?? null) === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'))
            ->when(($data['status'] ?? null) === 'escalated', fn (Builder $query) => $query->whereNull('resolved_at')->whereNotNull('escalated_at'))
            ->when(($data['status'] ?? null) === 'unacknowledged', fn (Builder $query) => $query->whereNull('resolved_at')
                ->whereHas('recipients', fn (Builder $recipient) => $recipient->where('user_id', $request->user()->id)->whereNull('acknowledged_at')))
            ->orderByDesc('opened_at')->orderByDesc('id');

        return response()->json($query->paginate($data['per_page'] ?? 25)->through(
            fn (MarketplaceOperationIncident $incident): array => $this->presentIncident($request, $incident),
        ));
    }

    public function orders(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $query = $this->orderQuery($request->user())->with(['account.platform', 'items.product'])
            ->when(filled($data['q'] ?? null), function (Builder $query) use ($data): void {
                $term = '%'.trim($data['q']).'%';
                $query->where(fn (Builder $scope) => $scope->where('external_order_id', 'like', $term)
                    ->orWhereHas('account', fn (Builder $account) => $account->where('name', 'like', $term))
                    ->orWhereHas('items', fn (Builder $item) => $item->where('external_sku', 'like', $term)->orWhere('title', 'like', $term)));
            })->orderByDesc('detected_at')->orderByDesc('id');

        return response()->json($query->paginate($data['per_page'] ?? 25)->through(
            fn (MarketplaceOrderEvent $event): array => $this->presentOrder($event),
        ));
    }

    public function incident(Request $request, int $incident): JsonResponse
    {
        $incident = $this->incidentQuery($request->user())
            ->with(['product.brandRelation', 'platform', 'account', 'responsibleEmployee', 'responsibleTeam', 'recipients'])
            ->findOrFail($incident);

        return response()->json(['data' => $this->presentIncident($request, $incident, true)]);
    }

    public function acknowledge(Request $request, int $incident): JsonResponse
    {
        $incident = $this->incidentQuery($request->user())->findOrFail($incident);
        app(MarketplaceIncidentAcknowledgementService::class)->acknowledgeIncident($request->user(), $incident);

        return $this->incident($request, $incident->id);
    }

    public function order(Request $request, int $event): JsonResponse
    {
        $event = $this->orderQuery($request->user())->with(['account.platform', 'items.product'])->findOrFail($event);

        return response()->json(['data' => $this->presentOrder($event, true)]);
    }

    private function incidentQuery(User $user): Builder
    {
        $query = MarketplaceOperationIncident::query();
        if (! $this->hasOversight($user)) {
            $query->whereHas('recipients', fn (Builder $recipient) => $recipient->where('user_id', $user->id));
        }

        return $query;
    }

    private function orderQuery(User $user): Builder
    {
        $query = MarketplaceOrderEvent::query();
        if (! $this->hasOversight($user)) {
            $ids = $user->notifications()->where('data->target_type', 'marketplace_order_event')
                ->whereNotNull('data->target_id')->get()->pluck('data.target_id')
                ->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id);
            $query->whereIn('id', $ids);
        }

        return $query;
    }

    private function hasOversight(User $user): bool
    {
        return app(MarketplaceOperationsAuthorization::class)->allows($user, MarketplaceOperationsPermission::View);
    }

    private function presentIncident(Request $request, MarketplaceOperationIncident $incident, bool $detail = false): array
    {
        $recipient = $incident->recipients->firstWhere('user_id', $request->user()->id);
        $title = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST
            ? 'Featured Offer Lost'
            : 'Marketplace Stock Exposure';
        $data = [
            'id' => $incident->id,
            'title' => $title,
            'subtitle' => collect([$incident->product?->sku, $incident->product?->name])->filter()->implode(' · '),
            'meta' => collect([$incident->platform?->name, $incident->account?->name])->filter()->implode(' · '),
            'status' => $incident->resolved_at !== null ? 'resolved' : ($incident->escalated_at !== null ? 'escalated' : 'open'),
            'acknowledgment_required' => $recipient !== null,
            'acknowledged' => $recipient?->acknowledged_at !== null,
            'acknowledged_at' => $recipient?->acknowledged_at?->toIso8601String(),
            'target' => ['module' => 'marketplace-operations/incidents', 'id' => $incident->id],
        ];
        if (! $detail) {
            return $data;
        }
        $fields = [
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
        ];
        $actions = [];
        if ($recipient !== null && $recipient->acknowledged_at === null && $incident->resolved_at === null) {
            $actions[] = $this->action('acknowledge', 'Acknowledge incident', confirmationMessage: 'Acknowledgment confirms awareness. Resolution remains monitor-driven.');
        }

        return [...$data, 'fields' => $fields, 'field_schema' => $this->readFields($fields), 'actions' => $actions];
    }

    private function presentOrder(MarketplaceOrderEvent $event, bool $detail = false): array
    {
        $data = [
            'id' => $event->id,
            'title' => 'Marketplace Order '.$event->external_order_id,
            'subtitle' => collect([$event->account?->platform?->name, $event->account?->name])->filter()->implode(' · '),
            'meta' => $event->detected_at?->toIso8601String(),
            'status' => 'detected',
            'target' => ['module' => 'marketplace-operations/orders', 'id' => $event->id],
        ];
        if (! $detail) {
            return $data;
        }

        return [...$data, 'fields' => [
            'external_order_id' => $event->external_order_id,
            'platform' => $event->account?->platform?->name,
            'account' => $event->account?->name,
            'source' => $event->source,
            'detected_at' => $event->detected_at?->toIso8601String(),
        ], 'items' => $event->items->map(fn ($item): array => [
            'id' => $item->id,
            'sku' => $item->external_sku,
            'product' => $item->product?->name ?? $item->title,
            'condition' => $item->product_condition,
            'quantity' => $item->quantity,
        ])->values(), 'actions' => []];
    }
}
