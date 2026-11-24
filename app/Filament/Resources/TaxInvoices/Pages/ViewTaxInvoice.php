<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\TaxInvoicePriceCorrection;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoicePriceCorrectionService;
use App\Services\Invoices\TaxInvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\View\View;

class ViewTaxInvoice extends ViewRecord
{
    protected static string $resource = TaxInvoiceResource::class;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->record->loadMissing('customerDetailAmendments.actor');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label('Back to Invoices')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(TaxInvoiceResource::getUrl('index')),
            TaxInvoiceResource::editCustomerDetailsAction(),
            Action::make('correctPrices')
                ->label('Request Price Adjustment')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->modalHeading('Request VAT Price Adjustment Review')
                ->modalDescription('Submit changed VAT-inclusive prices for finance review. The original issued Invoice remains valid and unchanged. No credit note, additional tax invoice, payment, journal or VAT adjustment is issued at this stage.')
                ->modalWidth('4xl')
                ->visible(fn (): bool => $this->record->status === 'issued'
                    && ($user = auth()->user()) instanceof User
                    && app(InvoiceAuthorization::class)->allows($user, InvoicePermission::EditPrices, $this->record)
                    && ! TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)->where('status', 'pending_review')->exists())
                ->fillForm(fn (): array => [
                    'items' => $this->record->items()->get()->map(fn ($item): array => [
                        'id' => $item->id,
                        'description' => $item->description,
                        'quantity' => $item->quantity,
                        'unit_price_including_vat' => $item->unit_price_including_vat,
                    ])->all(),
                ])
                ->schema([
                    Repeater::make('items')
                        ->label('Invoice Lines (AED, VAT inclusive)')
                        ->schema([
                            Hidden::make('id')->required(),
                            Hidden::make('description'),
                            Hidden::make('quantity'),
                            Placeholder::make('product')->label('Product')->content(fn (Get $get): string => (string) $get('description')),
                            Placeholder::make('qty')->label('Qty')->content(fn (Get $get): string => (string) $get('quantity')),
                            TextInput::make('unit_price_including_vat')
                                ->label('New Unit Price Incl. VAT (AED)')
                                ->numeric()->required()->minValue(0.01),
                        ])
                        ->columns(3)->deletable(false)->addable(false)->reorderable(false),
                    Textarea::make('reason')->label('Reason for Price Correction')
                        ->required()->maxLength(2000),
                ])
                ->modalSubmitActionLabel('Submit for Finance Review')
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    $proposal = app(TaxInvoicePriceCorrectionService::class)->propose($this->record, $data, $user);
                    Notification::make()->success()->title('Finance review requested')
                        ->body('Request #'.$proposal->id.' saved. Issued Invoice remains unchanged; no VAT adjustment has been posted.')->send();
                }),
            Action::make('priceAdjustmentRequests')
                ->label('Price Adjustment Requests')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->slideOver()
                ->modalHeading('VAT Adjustment Review Requests')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (): bool => TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)->exists())
                ->modalContent(fn (): View => view('filament.resources.tax-invoices.partials.price-adjustment-proposals', [
                    'proposals' => TaxInvoicePriceAdjustmentProposal::query()
                        ->with('requestedBy')
                        ->where('tax_invoice_id', $this->record->id)->latest()->get(),
                ])),
            Action::make('priceCorrectionHistory')
                ->label('Price Correction History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->slideOver()
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (): bool => TaxInvoicePriceCorrection::query()
                    ->where('original_invoice_id', $this->record->id)
                    ->orWhere('replacement_invoice_id', $this->record->id)->exists())
                ->modalContent(fn (): View => view('filament.resources.tax-invoices.partials.price-correction-history', [
                    'corrections' => TaxInvoicePriceCorrection::query()
                        ->with(['originalInvoice.items', 'replacementInvoice.items', 'actor'])
                        ->where('original_invoice_id', $this->record->id)
                        ->orWhere('replacement_invoice_id', $this->record->id)
                        ->get(),
                ])),

            Action::make('viewAmendmentHistory')
                ->label('View Amendment History')
                ->icon('heroicon-o-clock')
                ->color('gray')
                ->slideOver()
                ->modalHeading('Customer Amendment History')
                ->modalDescription('Internal audit history for customer identity and contact corrections.')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->modalContent(fn (): View => view('filament.resources.tax-invoices.partials.customer-amendment-history', [
                    'amendments' => $this->record->customerDetailAmendments,
                ]))
                ->visible(fn (): bool => $this->record->customerDetailAmendments->isNotEmpty()),
            Action::make('pdf')->label('Download PDF')->url(fn () => route('tax-invoices.pdf', ['invoice' => $this->record]))->openUrlInNewTab()->visible(fn () => auth()->user()->can(InvoicePermission::DownloadPdf->value)),
            Action::make('print')->label('Print')->icon('heroicon-o-printer')->url(fn () => route('tax-invoices.pdf', ['invoice' => $this->record, 'print' => 1]))->openUrlInNewTab()->visible(fn () => auth()->user()->can(InvoicePermission::DownloadPdf->value)),
            Action::make('void')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()])->visible(fn () => $this->record->status === 'issued' && auth()->user()->can(InvoicePermission::Void->value))->action(fn (array $data) => app(TaxInvoiceService::class)->void($this->record, $data['reason'], auth()->user())),
        ];
    }
}
