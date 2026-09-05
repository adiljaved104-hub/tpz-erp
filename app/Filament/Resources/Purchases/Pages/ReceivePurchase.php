<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Actions\Purchases\ReceivePurchase as ReceivePurchaseAction;
use App\DTOs\Purchases\PurchaseReceiptItemData;
use App\DTOs\Purchases\ReceivePurchaseData;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Purchases\Schemas\PurchaseReceiptForm;
use App\Models\Purchase;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class ReceivePurchase extends Page
{
    use InteractsWithRecord;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.pages.receive-purchase';

    public ?array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->authorizeReceiveAccess();

        /** @var Purchase $purchase */
        $purchase = $this->getRecord();
        $purchase->loadMissing(['items.product', 'supplier']);

        $this->form->fill(['received_at' => now(), 'idempotency_key' => (string) Str::uuid(), 'items' => $purchase->items->filter(fn ($item) => $item->outstandingQuantity() > 0)->map(fn ($item): array => [
            'purchase_item_id' => $item->id, 'product_label' => "{$item->product->name} — {$item->product->sku}",
            'outstanding_quantity' => $item->outstandingQuantity(), 'accepted_quantity' => 0, 'damaged_quantity' => 0, 'rejected_quantity' => 0,
        ])->values()->all()]);
    }

    public function hydrate(): void
    {
        $this->authorizeReceiveAccess();
    }

    public function form(Schema $schema): Schema
    {
        return PurchaseReceiptForm::configure($schema)->statePath('data');
    }

    public function receive(): void
    {
        $this->authorizeReceiveAccess();
        /** @var Purchase $purchase */
        $purchase = $this->getRecord();
        $data = $this->form->getState();
        $items = array_map(fn (array $item): PurchaseReceiptItemData => new PurchaseReceiptItemData(
            (int) $item['purchase_item_id'], (int) $item['accepted_quantity'], (int) $item['damaged_quantity'], (int) $item['rejected_quantity'], $item['notes'] ?? null,
        ), array_values($data['items']));
        $receipt = app(ReceivePurchaseAction::class)->handle($purchase, new ReceivePurchaseData(
            $items, (string) $data['received_at'], (string) $data['idempotency_key'], $data['supplier_delivery_note'] ?? null, $data['notes'] ?? null,
        ), auth()->user());
        Notification::make()->success()->title("GRN {$receipt->reference} posted")->send();
        $this->redirect(PurchaseResource::getUrl('view', ['record' => $purchase]));
    }

    public function receiveRemaining(): void
    {
        $this->authorizeReceiveAccess();
        $items = collect($this->data['items'] ?? [])->map(function (array $item): array {
            $item['accepted_quantity'] = (int) ($item['outstanding_quantity'] ?? 0);
            $item['damaged_quantity'] = 0;
            $item['rejected_quantity'] = 0;

            return $item;
        })->all();
        $this->data['items'] = $items;

        Notification::make()->success()->title('Remaining quantities filled')->body('Review accepted, damaged, and rejected quantities before posting.')->send();
    }

    private function authorizeReceiveAccess(): void
    {
        $record = $this->getRecord();

        abort_unless(
            $record instanceof Purchase
            && auth()->user()?->can('receive', $record)
            && $record->status->isOpenForReceiving(),
            403,
        );
    }
}
