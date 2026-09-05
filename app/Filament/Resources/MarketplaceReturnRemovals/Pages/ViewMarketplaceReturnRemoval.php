<?php

namespace App\Filament\Resources\MarketplaceReturnRemovals\Pages;

use App\DTOs\Returns\InspectCustomerReturnItemData;
use App\Enums\MarketplaceReturnRemovalStatus;
use App\Exceptions\CustomerReturnException;
use App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource;
use App\Services\Returns\MarketplaceReturnService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;

class ViewMarketplaceReturnRemoval extends ViewRecord
{
    protected static string $resource = MarketplaceReturnRemovalResource::class;

    public function infolist(Schema $s): Schema
    {
        return $s->components([Section::make('Marketplace Removal')->columns(2)->schema([TextEntry::make('reference'), TextEntry::make('status')->badge(), TextEntry::make('platform.name')->label('Platform'), TextEntry::make('sourceWarehouse.name')->label('From'), TextEntry::make('destinationWarehouse.name')->label('To'), TextEntry::make('external_removal_reference')->label('External Reference')->placeholder('—')]), Section::make('Items')->schema([RepeatableEntry::make('displayItems')->schema([TextEntry::make('product.sku')->label('SKU'), TextEntry::make('product.name')->label('Product'), TextEntry::make('source_stock_type')->label('Source'), TextEntry::make('quantity')->label('Qty'), TextEntry::make('dispatched_quantity')->label('Dispatched'), TextEntry::make('received_quantity')->label('Received')])])]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('dispatch')->label('Mark Dispatched')->requiresConfirmation()->visible(fn () => $this->record->status === MarketplaceReturnRemovalStatus::Requested && auth()->user()->can('marketplace_return.dispatch_to_company', $this->record))->action(fn () => $this->run('Cannot dispatch removal', fn () => app(MarketplaceReturnService::class)->dispatch($this->record, (string) str()->uuid(), auth()->user()))),
            Action::make('receive')->label('Receive at Company')->color('success')->requiresConfirmation()->visible(fn () => $this->record->status === MarketplaceReturnRemovalStatus::Dispatched && auth()->user()->can('marketplace_return.receive_company', $this->record))->action(fn () => $this->run('Cannot receive removal', fn () => app(MarketplaceReturnService::class)->receive($this->record, (string) str()->uuid(), auth()->user()))),
            Action::make('inspect')->label('QC Marketplace Removal')->color('success')->schema([
                Select::make('item_id')->label('Removal Item')->options(fn () => $this->record->items()->select(['id', 'marketplace_return_removal_id', 'product_id', 'received_quantity'])->with('product:id,sku,name')->get()->mapWithKeys(fn ($item) => [$item->id => "{$item->product->sku} - {$item->product->name} ({$item->received_quantity})"]))->required(),
                TextInput::make('sellable_quantity')->label('Sellable Qty')->integer()->minValue(0)->default(0)->required(),
                TextInput::make('damaged_quantity')->label('Damaged Qty')->integer()->minValue(0)->default(0)->required(),
                Textarea::make('notes')->maxLength(2000),
            ])->visible(fn () => $this->record->status === MarketplaceReturnRemovalStatus::Received && auth()->user()->can('return.inspect'))->action(fn (array $data) => $this->run('Cannot inspect Marketplace Removal', fn () => app(MarketplaceReturnService::class)->inspectRemovalItem($this->record->items()->findOrFail($data['item_id']), new InspectCustomerReturnItemData((int) $data['sellable_quantity'], (int) $data['damaged_quantity'], (string) str()->uuid(), $data['notes'] ?? null), auth()->user()))),
        ];
    }

    private function run(string $t, callable $o): void
    {
        try {
            $o();
            $this->record->refresh();
        } catch (CustomerReturnException$e) {
            Notification::make()->danger()->title($t)->body($e->getMessage())->send();
            throw new Halt;
        }
    }
}
