<?php

namespace App\Livewire;

use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Models\InventoryReservation;
use App\Models\MarketplacePlatform;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Inventory\InventoryReadService;
use App\Services\Orders\OrderReservationReleaseService;
use Filament\Notifications\Notification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkspaceReservations extends Component
{
    use WithPagination;

    public string $search = '';

    public string $reason = '';

    public string $status = '';

    public string $location = '';

    public string $platform = '';

    public function applyFilters(): void
    {
        $this->validate(['search' => ['string', 'max:200'], 'status' => ['in:,active,released,fulfilled'], 'location' => ['nullable', 'integer', 'min:1'], 'platform' => ['nullable', 'integer', 'min:1']]);
        $this->resetPage('reservation-page');
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'location', 'platform');
        $this->resetPage('reservation-page');
    }

    #[Locked]
    public ?int $releaseReservationId = null;

    #[Locked]
    public ?string $operationKey = null;

    #[Locked]
    public ?int $inventoryId = null;

    #[Locked]
    public ?int $orderId = null;

    #[Locked]
    public bool $salesOnly = false;

    private function query(bool $filters = true)
    {
        $user = auth()->user();
        abort_unless($user && app(InventoryAuthorization::class)->allows($user, InventoryPermission::View), 403);

        return InventoryReservation::query()->whereIn('product_inventory_id', app(InventoryReadService::class)->inventories($user)->select('product_inventories.id'))
            ->when($this->inventoryId !== null, fn ($q) => $q->where('product_inventory_id', $this->inventoryId))
            ->when($this->orderId !== null, fn ($q) => $q->whereHas('orderItem', fn ($q) => $q->where('order_id', $this->orderId)))
            ->when($this->salesOnly, fn ($q) => $q->whereHas('orderItem.order', fn ($q) => $q->whereNull('web_sales_channel')))
            ->when($filters && $this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($filters && $this->location !== '', fn ($q) => $q->where('warehouse_id', $this->location))
            ->when($filters && $this->platform !== '', fn ($q) => $q->whereHas('orderItem.order', fn ($q) => $q->where('marketplace_platform_id', $this->platform)))
            ->with(['product:id,sku,name', 'warehouse:id,name', 'orderItem.order', 'reservedBy:id,name'])
            ->when($filters && trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->where('reference', 'like', '%'.addcslashes(trim($this->search), '%_').'%')->orWhereHas('product', fn ($q) => $q->where('sku', 'like', '%'.addcslashes(trim($this->search), '%_').'%')->orWhere('name', 'like', '%'.addcslashes(trim($this->search), '%_').'%'))->orWhereHas('orderItem.order', fn ($q) => $q->where('reference', 'like', '%'.addcslashes(trim($this->search), '%_').'%'))));
    }

    public function prepareRelease(int $reservationId): void
    {
        $record = $this->query()->findOrFail($reservationId);
        abort_unless($record->status === InventoryReservationStatus::Active && $record->orderItem?->order
            && app(OrderReservationReleaseService::class)->allows(auth()->user(), $record->orderItem->order), 403);
        $this->releaseReservationId = $record->id;
        $this->reason = '';
        $this->operationKey = (string) Str::uuid();
    }

    public function confirmRelease(): void
    {
        $this->validate(['reason' => ['required', 'string', 'max:2000']]);
        $record = $this->query()->findOrFail($this->releaseReservationId);
        abort_unless($record->orderItem?->order && $this->operationKey, 403);
        try {
            app(OrderReservationReleaseService::class)->release($record->orderItem->order, auth()->user(), $this->reason, $this->operationKey);
        } catch (ValidationException|AuthorizationException|HttpException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()->warning()->title('Reservations were not released')
                ->body('Stock or order state could not be verified. Refresh and ask an authorized manager to review. No partial release was saved.')->send();

            return;
        }
        $this->releaseReservationId = null;
        $this->dispatch('workspace-stock-changed');
        Notification::make()->success()->title('Order reservations released; order returned to Draft')->send();
    }

    public function cancelRelease(): void
    {
        $this->releaseReservationId = null;
    }

    public function render()
    {
        $pending = $this->releaseReservationId ? $this->query()->findOrFail($this->releaseReservationId) : null;

        return view('livewire.workspace-reservations', [
            'locations' => $this->query(false)->get()->pluck('warehouse')->unique('id')->pluck('name', 'id'),
            'platforms' => MarketplacePlatform::query()->whereIn('id', $this->query(false)->get()->pluck('orderItem.order.marketplace_platform_id')->filter()->unique())->pluck('name', 'id'),
            'rows' => $this->query()->latest('id')->paginate(15, pageName: 'reservation-page'),
            'pending' => $pending,
            'bundle' => $pending?->orderItem?->order ? app(OrderReservationReleaseService::class)->reservations($pending->orderItem->order)->where('status', InventoryReservationStatus::Active)->with('product:id,sku,name', 'warehouse:id,name')->get() : collect(),
        ]);
    }
}
