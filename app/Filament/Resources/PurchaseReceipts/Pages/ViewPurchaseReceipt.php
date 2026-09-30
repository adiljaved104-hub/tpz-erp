<?php

namespace App\Filament\Resources\PurchaseReceipts\Pages;

use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchaseReceipt extends ViewRecord
{
    protected static string $resource = PurchaseReceiptResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createStockAdjustment')
                ->label('Create Stock Adjustment')
                ->visible(fn (): bool => $this->canCreateStockAdjustment())
                ->modalHeading('Link a stock adjustment to this GRN')
                ->modalDescription('The posted GRN and Purchase received quantities stay unchanged. The adjustment is recorded separately in Inventory.')
                ->schema([
                    Select::make('receipt_item_id')->label('Product')->required()->searchable()
                        ->options(fn (): array => $this->adjustableItems()),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->canCreateStockAdjustment(), 403);
                    $item = $this->record->items()->findOrFail((int) $data['receipt_item_id']);
                    $inventory = ProductInventory::query()->where('product_id', $item->product_id)
                        ->where('warehouse_id', $this->record->warehouse_id)->firstOrFail();
                    app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::AdjustStock, $inventory);
                    $this->redirect(StockAdjustments::getUrl(['receipt_item_id' => $item->id]), navigate: true);
                }),
        ];
    }

    private function canCreateStockAdjustment(): bool
    {
        $actor = auth()->user();

        return app(PurchaseAuthorization::class)->allows($actor, PurchasePermission::ViewReceipts, $this->record->purchase)
            && app(InventoryAuthorization::class)->allows($actor, InventoryPermission::AdjustStock);
    }

    /** @return array<int, string> */
    private function adjustableItems(): array
    {
        return $this->record->items()->with('product:id,name,sku')->orderBy('id')->get()
            ->filter(function (PurchaseReceiptItem $item): bool {
                $inventory = ProductInventory::query()->where('product_id', $item->product_id)
                    ->where('warehouse_id', $this->record->warehouse_id)->first();

                return $inventory !== null && app(InventoryAuthorization::class)->allows(auth()->user(), InventoryPermission::AdjustStock, $inventory);
            })
            ->mapWithKeys(fn (PurchaseReceiptItem $item): array => [$item->id => "{$item->product->sku} — {$item->product->name}"])
            ->all();
    }
}
