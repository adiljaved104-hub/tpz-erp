<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Actions\Purchases\ApprovePurchase;
use App\Actions\Purchases\CancelPurchase;
use App\Actions\Purchases\ClosePurchase;
use App\DTOs\Purchases\ApprovePurchaseData;
use App\DTOs\Purchases\CancelPurchaseData;
use App\DTOs\Purchases\ClosePurchaseData;
use App\Enums\PurchaseStatus;
use App\Filament\Resources\Purchases\PurchaseResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewPurchase extends ViewRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->visible(fn (): bool => $this->record->status === PurchaseStatus::Draft),
            Action::make('approve')->requiresConfirmation()
                ->modalDescription(fn (): string => 'Supplier: '.($this->record->supplier?->name ?? 'No Supplier'))
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => $this->record->status === PurchaseStatus::Draft && auth()->user()->can('approve', $this->record))
                ->action(fn (array $data) => app(ApprovePurchase::class)->handle($this->record, new ApprovePurchaseData($data['reason'], true), auth()->user())),
            Action::make('receive')->url(fn (): string => PurchaseResource::getUrl('receive', ['record' => $this->record]))
                ->visible(fn (): bool => $this->record->status->isOpenForReceiving() && auth()->user()->can('receive', $this->record)),
            Action::make('cancel')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => in_array($this->record->status, [PurchaseStatus::Draft, PurchaseStatus::Approved], true) && auth()->user()->can('cancel', $this->record))
                ->action(fn (array $data) => app(CancelPurchase::class)->handle($this->record, new CancelPurchaseData($data['reason']), auth()->user())),
            Action::make('close')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn (): bool => in_array($this->record->status, [PurchaseStatus::Approved, PurchaseStatus::PartiallyReceived, PurchaseStatus::FullyReceived], true) && auth()->user()->can('close', $this->record))
                ->action(fn (array $data) => app(ClosePurchase::class)->handle($this->record, new ClosePurchaseData($data['reason'], true), auth()->user())),
        ];
    }
}
