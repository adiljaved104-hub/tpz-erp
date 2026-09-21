<?php

namespace App\Filament\Resources\TaxInvoices;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceOrderImportService;
use App\Services\Invoices\TaxInvoiceService;
use App\Services\Invoices\TaxInvoiceZipService;
use App\Support\AedMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class TaxInvoiceResource extends Resource
{
    protected static ?string $model = TaxInvoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCurrencyDollar;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Invoices';

    protected static ?string $recordTitleAttribute = 'invoice_number';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? app(InvoiceAuthorization::class)->scope(parent::getEloquentQuery(), $user)->with(['items', 'createdBy']) : parent::getEloquentQuery()->whereRaw('1=0');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Import from Order')
                ->description('Optional. Search by ERP Order Number or External / Marketplace Order ID, then review and adjust the Invoice before saving. Manual entry remains available.')
                ->schema([
                    Select::make('source_order_id')
                        ->label('Import from Order')
                        ->placeholder('Search an Order to prefill this Invoice')
                        ->searchable()
                        ->searchPrompt('Type an ERP or External Order ID')
                        ->getSearchResultsUsing(fn (string $search): array => ($user = auth()->user()) instanceof User
                            ? app(TaxInvoiceOrderImportService::class)->options($user, $search)
                            : [])
                        ->getOptionLabelUsing(fn ($value): ?string => ($user = auth()->user()) instanceof User
                            ? app(TaxInvoiceOrderImportService::class)->label($user, (int) $value)
                            : null)
                        ->live()
                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                            $user = auth()->user();
                            $prefill = $user instanceof User && filled($state)
                                ? app(TaxInvoiceOrderImportService::class)->prefill($user, (int) $state)
                                : null;
                            if ($prefill === null) {
                                $set('source_order_id', null);
                                $items = (array) ($get('items') ?? []);
                                foreach ($items as &$item) {
                                    unset($item['source_order_item_id']);
                                }
                                unset($item);
                                $set('items', $items);

                                return;
                            }

                            $set('order_reference', $prefill['order_reference']);
                            if (filled($prefill['customer_name'])) {
                                $set('customer_name', $prefill['customer_name']);
                            }
                            $set('items', $prefill['items']);
                        }),
                    Placeholder::make('source_order_context')
                        ->label('Source Order')
                        ->content(fn (Get $get): string => filled($get('source_order_id'))
                            ? 'Imported Order lines may be edited or partially invoiced. The source may be used again for another Invoice.'
                            : 'Enter Invoice details manually, or import an Order above.'),
                ]),
            Section::make('Customer & Invoice')
                ->description('Customer details and the related sales reference for this issued Invoice.')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    TextInput::make('customer_name')
                        ->label('Customer Name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('customer_trn')
                        ->label('Customer TRN (Optional)')
                        ->maxLength(50),
                    Textarea::make('customer_address')
                        ->label('Customer Address')
                        ->required()
                        ->rows(3)
                        ->columnSpanFull(),
                    TextInput::make('order_reference')
                        ->label('Order ID / Reference')
                        ->required()
                        ->maxLength(100),
                    DatePicker::make('invoice_date')
                        ->label('Invoice Date')
                        ->default(today())
                        ->required(),
                ]),
            Section::make('Items')
                ->description('Enter VAT-inclusive selling prices. Each line total updates automatically.')
                ->schema([
                    Repeater::make('items')
                        ->hiddenLabel()
                        ->schema([
                            Hidden::make('source_order_item_id'),
                            Textarea::make('description')
                                ->label('Product Name / Description')
                                ->required()
                                ->rows(2)
                                ->maxLength(2000)
                                ->columnSpan(['default' => 1, 'md' => 5]),
                            TextInput::make('quantity')
                                ->label('Qty')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(['default' => 1, 'md' => 2]),
                            TextInput::make('unit_price_including_vat')
                                ->label('Unit Price Incl. VAT (AED)')
                                ->numeric()
                                ->minValue(0.01)
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(['default' => 1, 'md' => 3]),
                            Placeholder::make('line_total_including_vat')
                                ->label('Line Total Incl. VAT')
                                ->content(fn (Get $get): string => self::lineTotalPreview($get))
                                ->extraAttributes(['class' => 'font-semibold tabular-nums'])
                                ->columnSpan(['default' => 1, 'md' => 2]),
                        ])
                        ->columns(['default' => 1, 'md' => 12])
                        ->itemNumbers()
                        ->minItems(1)
                        ->maxItems(100)
                        ->defaultItems(1)
                        ->addActionLabel('+ Add Item'),
                ]),
            Section::make('Totals Summary')->schema([
                Placeholder::make('totals_preview')->hiddenLabel()->content(fn (Get $get): HtmlString => self::totalsPreview($get))->columnSpanFull(),
            ]),
            Hidden::make('idempotency_key')->default(fn () => (string) str()->uuid()),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tax Invoice')->columns(4)->schema([
                TextEntry::make('invoice_number')->label('Invoice #'), TextEntry::make('order_reference')->label('Order ID')->placeholder('—'), TextEntry::make('invoice_date')->date('d M Y'), TextEntry::make('status')->badge(),
                TextEntry::make('customer_name'), TextEntry::make('customer_trn')->label('Customer TRN')->placeholder('—'), TextEntry::make('customer_address')->columnSpan(2)->placeholder('—'),
                TextEntry::make('subtotal_excluding_vat')->money('AED'), TextEntry::make('vat_amount')->label('VAT')->money('AED'), TextEntry::make('grand_total')->money('AED')->weight('bold'), TextEntry::make('createdBy.name')->label('Created By'),
            ]),
            Section::make('Record Information')
                ->description('Internal ERP metadata. This information is not printed on the customer Invoice.')
                ->compact()
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextEntry::make('invoice_date')->label('Invoice Date')->date('d M Y'),
                    TextEntry::make('created_at')->label('Created At')->dateTime('d M Y, h:i A', config('app.timezone')),
                    TextEntry::make('createdBy.name')->label('Created By')->placeholder('Not recorded'),
                    TextEntry::make('last_customer_amendment_at')
                        ->label('Last Customer Amendment At')
                        ->state(fn (TaxInvoice $record) => self::amendments($record)->first()?->created_at)
                        ->dateTime('d M Y, h:i A', config('app.timezone'))
                        ->placeholder('No amendments'),
                    TextEntry::make('last_customer_amendment_actor')
                        ->label('Last Amended By')
                        ->state(fn (TaxInvoice $record): ?string => self::amendments($record)->first()?->actor?->name)
                        ->placeholder('No amendments'),
                    TextEntry::make('customer_amendment_count')
                        ->label('Amendment Count')
                        ->state(fn (TaxInvoice $record): int => self::amendments($record)->count()),
                ]),
            RepeatableEntry::make('items')->schema([TextEntry::make('description'), TextEntry::make('quantity'), TextEntry::make('unit_price_including_vat')->money('AED'), TextEntry::make('total_including_vat')->money('AED')])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_number')->label('Invoice #')->searchable()->sortable(),
            TextColumn::make('order_reference')->label('Order ID')->searchable(),
            TextColumn::make('invoice_date')->label('Invoice Date')->date('d M Y')->sortable(),
            TextColumn::make('created_at')->label('Created At')->dateTime('d M Y, h:i A', config('app.timezone'))->sortable(),
            TextColumn::make('customer_name')->label('Customer')->searchable(),
            TextColumn::make('customer_trn')->label('Customer TRN')->searchable()->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('createdBy.name')->label('Created By'),
            TextColumn::make('grand_total')->label('Total AED')->money('AED'),
            TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('status')->options(['issued' => 'Issued', 'void' => 'Void']),
            SelectFilter::make('created_by_user_id')->label('Created By')->relationship('createdBy', 'name')->searchable(),
            Filter::make('invoice_date')
                ->schema([
                    DatePicker::make('from')->label('Invoice Date From'),
                    DatePicker::make('to')->label('Invoice Date To'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '>=', $date))
                    ->when($data['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '<=', $date))),
            Filter::make('created_at')
                ->label('Created Date')
                ->schema([
                    DatePicker::make('created_from')->label('Created From'),
                    DatePicker::make('created_to')->label('Created To'),
                ])
                ->query(fn (Builder $query, array $data): Builder => $query
                    ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '>=', Carbon::parse($date, config('app.timezone'))->startOfDay()->utc()))
                    ->when($data['created_to'] ?? null, fn (Builder $q, $date) => $q->where('created_at', '<=', Carbon::parse($date, config('app.timezone'))->endOfDay()->utc()))),
        ])->recordActions([
            ViewAction::make(),
            self::editCustomerDetailsAction(),
            Action::make('pdf')
                ->label('PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->url(fn (TaxInvoice $record): string => route('tax-invoices.pdf', ['invoice' => $record]))
                ->openUrlInNewTab()
                ->visible(fn (TaxInvoice $record): bool => self::allows($record, InvoicePermission::DownloadPdf)),
            Action::make('void')
                ->label('Void')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (TaxInvoice $record): string => "Void Invoice {$record->invoice_number}")
                ->modalDescription('The Invoice will remain in financial history and its number will not be reused.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Void Reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (TaxInvoice $record): bool => $record->status !== 'void' && self::allows($record, InvoicePermission::Void))
                ->action(function (TaxInvoice $record, array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    app(TaxInvoiceService::class)->void($record, $data['reason'], $user);

                    Notification::make()
                        ->success()
                        ->title('Invoice voided')
                        ->body("{$record->invoice_number} remains available in Invoice history.")
                        ->send();
                }),
        ])->toolbarActions([
            BulkAction::make('downloadSelectedPdfs')
                ->label('Download Selected PDFs (.zip)')
                ->icon('heroicon-o-archive-box-arrow-down')
                ->color('primary')
                ->deselectRecordsAfterCompletion()
                ->visible(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(InvoiceAuthorization::class)->allows($user, InvoicePermission::DownloadPdf))
                ->action(function (Collection $records, Component $livewire) {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    $requestedKeys = $livewire->isTrackingDeselectedTableRecords
                        ? null
                        : $livewire->selectedTableRecords;

                    try {
                        return app(TaxInvoiceZipService::class)->download($records, $user, $requestedKeys);
                    } catch (ValidationException $exception) {
                        Notification::make()
                            ->danger()
                            ->title('Invoice ZIP could not be downloaded')
                            ->body(collect($exception->errors())->flatten()->unique()->implode(' '))
                            ->send();

                        throw $exception;
                    }
                }),
        ]);
    }

    public static function canViewAny(): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(InvoiceAuthorization::class)->allows($u, InvoicePermission::View);
    }

    public static function canCreate(): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(InvoiceAuthorization::class)->allows($u, InvoicePermission::Create);
    }

    public static function canView(Model $record): bool
    {
        $u = auth()->user();

        return $u instanceof User && app(InvoiceAuthorization::class)->allows($u, InvoicePermission::View, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return ['index' => ListTaxInvoices::route('/'), 'create' => CreateTaxInvoice::route('/create'), 'view' => ViewTaxInvoice::route('/{record}')];
    }

    private static function allows(TaxInvoice $invoice, InvoicePermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InvoiceAuthorization::class)->allows($user, $permission, $invoice);
    }

    public static function editCustomerDetailsAction(): Action
    {
        return Action::make('editCustomerDetails')
            ->label('Edit Customer Details')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->slideOver()
            ->modalHeading(fn (TaxInvoice $record): string => "Edit Customer Details · {$record->invoice_number}")
            ->modalDescription('Only customer identity and contact details will change. Invoice items, dates, totals, VAT and financial values will remain unchanged.')
            ->modalSubmitActionLabel('Save Customer Details')
            ->fillForm(fn (TaxInvoice $record): array => [
                'customer_name' => $record->customer_name,
                'customer_trn' => $record->customer_trn,
                'customer_address' => $record->customer_address,
            ])
            ->schema([
                TextInput::make('customer_name')
                    ->label('Customer Name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('customer_trn')
                    ->label('Customer TRN')
                    ->maxLength(50),
                Textarea::make('customer_address')
                    ->label('Customer Address')
                    ->required()
                    ->rows(4)
                    ->maxLength(2000),
                Textarea::make('amendment_reason')
                    ->label('Reason for Change')
                    ->required()
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->visible(fn (TaxInvoice $record): bool => $record->status !== 'void'
                && self::allows($record, InvoicePermission::EditCustomerDetails))
            ->action(function (TaxInvoice $record, array $data): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                app(TaxInvoiceService::class)->updateCustomerDetails($record, $data, $user);
                $record->refresh();

                Notification::make()
                    ->success()
                    ->title('Customer details updated')
                    ->body("{$record->invoice_number} keeps the same items, totals and Invoice number.")
                    ->send();
            });
    }

    private static function amendments(TaxInvoice $invoice): Collection
    {
        $invoice->loadMissing('customerDetailAmendments.actor');

        return $invoice->customerDetailAmendments;
    }

    private static function totalsPreview(Get $get): HtmlString
    {
        $totals = app(TaxInvoiceService::class)->previewTotals((array) ($get('items') ?? []));

        return new HtmlString(
            '<div class="ml-auto w-full max-w-xl space-y-3 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-white/10 dark:bg-white/5">'
            .'<div class="flex items-center justify-between gap-4 text-sm"><span class="text-gray-600 dark:text-gray-300">Subtotal Excl. VAT</span><span class="font-medium tabular-nums">'.e(AedMoney::format($totals['subtotal'])).'</span></div>'
            .'<div class="flex items-center justify-between gap-4 text-sm"><span class="text-gray-600 dark:text-gray-300">VAT ('.e($totals['vat_rate']).'%)</span><span class="font-medium tabular-nums">'.e(AedMoney::format($totals['vat'])).'</span></div>'
            .'<div class="flex items-center justify-between gap-4 border-t border-gray-300 pt-3 dark:border-white/15"><span class="font-semibold text-gray-950 dark:text-white">Grand Total</span><span class="text-xl font-bold tabular-nums text-primary-600 dark:text-primary-400">'.e(AedMoney::format($totals['grand_total'])).'</span></div>'
            .'</div>',
        );
    }

    private static function lineTotalPreview(Get $get): string
    {
        $totals = app(TaxInvoiceService::class)->previewTotals([[
            'quantity' => $get('quantity'),
            'unit_price_including_vat' => $get('unit_price_including_vat'),
        ]]);

        return AedMoney::format($totals['grand_total']);
    }
}
