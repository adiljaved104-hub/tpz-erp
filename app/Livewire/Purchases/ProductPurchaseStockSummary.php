<?php

namespace App\Livewire\Purchases;

use App\Models\Purchase;
use App\Models\User;
use App\Services\Purchases\PurchaseProductContextService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ProductPurchaseStockSummary extends Component
{
    public int $warehouseId;

    /** @var array<int, int> */
    public array $productIds = [];

    public ?int $purchaseId = null;

    /** @var array<int, mixed> */
    public array $contexts = [];

    public function mount(int $warehouseId, array $productIds, ?int $purchaseId = null): void
    {
        $this->warehouseId = $warehouseId;
        $this->productIds = $productIds;
        $this->purchaseId = $purchaseId;
        $this->reloadContext();
    }

    public function reloadContext(): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $purchase = $this->purchaseId ? Purchase::query()->findOrFail($this->purchaseId) : null;
        $this->contexts = app(PurchaseProductContextService::class)->forProducts($user, $this->warehouseId, $this->productIds, $purchase);
    }

    public function render(): View
    {
        return view('livewire.purchases.product-purchase-stock-summary');
    }
}
