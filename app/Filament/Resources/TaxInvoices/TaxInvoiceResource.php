<?php

namespace App\Filament\Resources\TaxInvoices;

use App\Enums\InvoicePermission;
use App\Filament\Resources\TaxInvoices\Pages\CreateTaxInvoice;
use App\Filament\Resources\TaxInvoices\Pages\ListTaxInvoices;
use App\Filament\Resources\TaxInvoices\Pages\ViewTaxInvoice;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Invoices\TaxInvoiceService;
use App\Support\AedMoney;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

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
                            TextInput::make('description')
                                ->label('Product Name / Description')
                                ->required()
                                ->maxLength(255)
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
            ]), RepeatableEntry::make('items')->schema([TextEntry::make('description'), TextEntry::make('quantity'), TextEntry::make('unit_price_including_vat')->money('AED'), TextEntry::make('total_including_vat')->money('AED')])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('invoice_number')->label('Invoice #')->searchable()->sortable(), TextColumn::make('order_reference')->label('Order ID')->searchable(), TextColumn::make('invoice_date')->date('d M Y')->sortable(), TextColumn::make('customer_name')->label('Customer')->searchable(), TextColumn::make('customer_trn')->label('Customer TRN')->searchable()->toggleable(isToggledHiddenByDefault: true), TextColumn::make('createdBy.name')->label('Created By'), TextColumn::make('grand_total')->label('Total AED')->money('AED'), TextColumn::make('status')->badge(),
        ])->filters([
            SelectFilter::make('status')->options(['issued' => 'Issued', 'void' => 'Void']),
            SelectFilter::make('created_by_user_id')->label('Created By')->relationship('createdBy', 'name')->searchable(),
            Filter::make('invoice_date')->schema([DatePicker::make('from'), DatePicker::make('to')])->query(fn (Builder $query, array $data): Builder => $query->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '>=', $date))->when($data['to'] ?? null, fn (Builder $q, $date) => $q->whereDate('invoice_date', '<=', $date))),
        ])->recordActions([
            ViewAction::make(),
            Action::make('pdf')->label('PDF')->icon('heroicon-o-arrow-down-tray')->url(fn (TaxInvoice $record): string => route('tax-invoices.pdf', ['invoice' => $record]))->openUrlInNewTab()->visible(fn (): bool => auth()->user()?->can(InvoicePermission::DownloadPdf->value) === true),
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
