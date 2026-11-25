<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Models\AccountingAccount;
use App\Models\AccountingBook;
use App\Models\AccountingJournal;
use App\Models\TaxInvoiceAdjustmentDocument;
use App\Models\TaxInvoicePriceAdjustmentProposal;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceAdjustmentIssuanceService;
use App\Services\Invoices\TaxInvoicePriceCorrectionService;
use App\Services\Invoices\TaxInvoiceService;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
                    && ! TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereDoesntHave('decision')->exists()
                    && ! TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))->exists())
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
            Action::make('reviewAdjustment')
                ->label('Review Price Adjustment')
                ->icon('heroicon-o-check-badge')
                ->color('primary')
                ->visible(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(InvoiceAuthorization::class)->allows($user, InvoicePermission::ReviewPriceAdjustments)
                    && $this->record->status === 'issued'
                    && TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereDoesntHave('decision')->exists())
                ->schema([
                    Select::make('proposal_id')
                        ->label('Pending Request')
                        ->options(fn (): array => TaxInvoicePriceAdjustmentProposal::query()
                            ->where('tax_invoice_id', $this->record->id)
                            ->whereDoesntHave('decision')
                            ->orderByDesc('id')
                            ->get()->mapWithKeys(fn ($p): array => [$p->id => 'Request #'.$p->id.' — AED '.$p->gross_difference])->all())
                        ->required(),
                    Select::make('review_decision')
                        ->label('Finance Decision')
                        ->options(['approved' => 'Approve for finance processing (NOT issued)', 'rejected' => 'Reject request'])
                        ->required(),
                    Textarea::make('review_reason')->label('Review Reason')
                        ->required()->minLength(5)->maxLength(2000),
                ])
                ->modalHeading('Finance Price Adjustment Review')
                ->modalDescription('Approval records internal finance sign-off ONLY. No official Tax Credit Note, additional invoice, accounting journal, payment or VAT return adjustment will be issued or posted.')
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    $proposal = TaxInvoicePriceAdjustmentProposal::query()
                        ->where('tax_invoice_id', $this->record->id)
                        ->findOrFail((int) $data['proposal_id']);
                    app(TaxInvoicePriceCorrectionService::class)->review($proposal, $data['review_decision'], $data['review_reason'], $user);
                    Notification::make()->success()->title('Finance decision recorded')
                        ->body('Request reviewed. No tax documents or accounting entries were posted.')->send();
                }),
            Action::make('issuePriceAdjustmentDocuments')
                ->label('Issue Approved Tax Adjustments')
                ->icon('heroicon-o-document-check')
                ->color('danger')
                ->visible(fn (): bool => config('tax_invoice_adjustments.issuance_enabled', false) === true
                    && config('tax_invoice_adjustments.electronic_delivery_ready', false) === true
                    && ($user = auth()->user()) instanceof User
                    && app(InvoiceAuthorization::class)->allows($user, InvoicePermission::IssuePriceAdjustments)
                    && TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))
                        ->whereDoesntHave('documents')->exists())
                ->modalHeading('Issue Official Tax Adjustment Documents')
                ->modalDescription('HIGH IMPACT: creates numbered Tax Credit Notes and/or Additional Tax Invoices and POSTED AED accounting journals. Only use after payment reconciliation, original journal verification, and VAT/e-invoice compliance sign-off. This action cannot be undone.')
                ->modalWidth('3xl')
                ->schema([
                    Select::make('proposal_id')->label('Approved Request')
                        ->options(fn (): array => TaxInvoicePriceAdjustmentProposal::query()
                            ->where('tax_invoice_id', $this->record->id)
                            ->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))
                            ->whereDoesntHave('documents')->get()
                            ->mapWithKeys(fn ($p): array => [$p->id => 'Request #'.$p->id])->all())->required(),
                    Select::make('accounting_book_id')->label('Accounting Book (AED)')
                        ->options(fn (): array => AccountingBook::query()->where('active', true)
                            ->where('posting_enabled', true)->where('currency', 'AED')
                            ->pluck('name', 'id')->all())->required()->live(),
                    Select::make('original_accounting_journal_id')->label('Original Posted Invoice Journal')
                        ->options(fn (): array => AccountingJournal::query()
                            ->where('status', 'posted')->where('source_type', 'tax_invoice')
                            ->where('source_key', (string) $this->record->id)
                            ->pluck('reference', 'id')->all())->required(),
                    Select::make('receivable_account_id')->label('Accounts Receivable (Asset, posting account)')
                        ->options(fn (Get $get): array => self::adjustmentAccountOptions($get('accounting_book_id'), 'asset'))->required(),
                    Select::make('revenue_account_id')->label('Sales Revenue (Income, posting account)')
                        ->options(fn (Get $get): array => self::adjustmentAccountOptions($get('accounting_book_id'), 'income'))->required(),
                    Select::make('output_vat_account_id')->label('Output VAT (Liability, posting account)')
                        ->options(fn (Get $get): array => self::adjustmentAccountOptions($get('accounting_book_id'), 'liability'))->required(),
                    TextInput::make('financial_reconciliation_reference')->label('Payment / AR Reconciliation Evidence')
                        ->helperText('Document the original posted invoice and confirm customer payment/settlement consequences have been assessed.')->required()->minLength(10)->maxLength(190),
                    Checkbox::make('accountant_confirmation')
                        ->label('I confirm the adjustment is valid under UAE VAT rules, AR is reconciled, this issue date and VAT period are appropriate, and electronic document delivery obligations are satisfied.')
                        ->required()->accepted(),
                ])
                ->modalSubmitActionLabel('Issue Documents & Post Journals')
                ->requiresConfirmation()
                ->action(function (array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);
                    $proposal = TaxInvoicePriceAdjustmentProposal::query()
                        ->where('tax_invoice_id', $this->record->id)->findOrFail((int) $data['proposal_id']);
                    $documents = app(TaxInvoiceAdjustmentIssuanceService::class)->issue($proposal, $data, $user);
                    Notification::make()->success()->title('Official Tax Adjustments Issued')
                        ->body('Posted documents: '.$documents->pluck('document_number')->join(', '))->send();
                }),
            Action::make('issuedPriceAdjustmentDocuments')
                ->label('Issued Tax Adjustments')
                ->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->slideOver()
                ->modalHeading('Issued Tax Adjustment Documents')
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close')
                ->visible(fn (): bool => TaxInvoiceAdjustmentDocument::query()->where('original_invoice_id', $this->record->id)->exists())
                ->modalContent(fn (): View => view('filament.resources.tax-invoices.partials.issued-tax-adjustment-documents', [
                    'documents' => TaxInvoiceAdjustmentDocument::query()
                        ->where('original_invoice_id', $this->record->id)->orderBy('id')->get(),
                ])),
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
                        ->with(['requestedBy', 'decision.reviewedBy'])
                        ->where('tax_invoice_id', $this->record->id)->latest()->get(),
                ])),
            Action::make('downloadAmendedSummary')
                ->label('Download Amended Price Summary')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('primary')
                ->visible(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(InvoiceAuthorization::class)->allows($user, InvoicePermission::DownloadPdf, $this->record)
                    && TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))->exists())
                ->url(fn (): string => route('tax-invoices.amended-summary.pdf', [
                    'proposal' => TaxInvoicePriceAdjustmentProposal::query()->where('tax_invoice_id', $this->record->id)
                        ->whereHas('decision', fn ($query) => $query->where('decision', 'approved'))
                        ->latest('id')->firstOrFail(),
                ]))
                ->openUrlInNewTab(),
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

    private static function adjustmentAccountOptions(mixed $bookId, string $type): array
    {
        if (! is_numeric($bookId)) {
            return [];
        }

        return AccountingAccount::query()
            ->where('accounting_book_id', (int) $bookId)
            ->where('account_type', $type)
            ->where('active', true)->where('is_posting', true)->where('is_control', false)
            ->orderBy('number')->get()
            ->mapWithKeys(fn ($account): array => [$account->id => $account->number.' — '.$account->name])
            ->all();
    }
}
