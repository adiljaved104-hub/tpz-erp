<?php

namespace App\Filament\Resources\Quotations;

use App\Enums\ProductMatchContext;
use App\Enums\QuotationDocumentType;
use App\Enums\QuotationPermission;
use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\QuotationAuthorization;
use App\Services\DefaultWarehouseService;
use App\Services\ProductIntelligence\ProductSearchOptions;
use App\Services\Quotations\QuotationConversionService;
use App\Services\Quotations\QuotationEmailService;
use App\Services\Quotations\QuotationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $navigationLabel = 'Quotations';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quotation Details')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    Select::make('document_type')
                        ->options(QuotationDocumentType::options())
                        ->default('quotation')
                        ->required(),

                    DatePicker::make('quotation_date')
                        ->default(today())
                        ->required()
                        ->live(),

                    DatePicker::make('valid_until')
                        ->default(today()->addDays(14))
                        ->minDate(fn (Get $get) => $get('quotation_date'))
                        ->required(),

                    Select::make('customer_history')
                        ->label('Use Previous Customer (Optional)')
                        ->searchable()
                        ->dehydrated(false)
                        ->helperText('Choose a previous authorized quotation to copy its customer details. Existing input is never changed unless you select one.')
                        ->getSearchResultsUsing(function (string $search): array {
                            $user = auth()->user();

                            if (! $user instanceof User) {
                                return [];
                            }

                            return app(QuotationAuthorization::class)
                                ->scope(Quotation::query(), $user)
                                ->where(fn (Builder $q) => $q
                                    ->where('customer_name', 'like', "%{$search}%")
                                    ->orWhere('customer_phone', 'like', "%{$search}%")
                                    ->orWhere('customer_email', 'like', "%{$search}%"))
                                ->latest()
                                ->limit(20)
                                ->get()
                                ->mapWithKeys(fn (Quotation $q) => [
                                    $q->id => $q->customer_name.' · '.(
                                        $q->customer_phone
                                        ?: $q->customer_email
                                        ?: $q->reference
                                    ),
                                ])
                                ->all();
                        })
                        ->getOptionLabelUsing(function ($value): ?string {
                            $user = auth()->user();

                            if (! $user instanceof User || blank($value)) {
                                return null;
                            }

                            $q = app(QuotationAuthorization::class)
                                ->scope(Quotation::query(), $user)
                                ->find($value);

                            if (! $q) {
                                return null;
                            }

                            return $q->customer_name.' · '.(
                                $q->customer_phone
                                ?: $q->customer_email
                                ?: $q->reference
                            );
                        })
                        ->afterStateUpdated(function ($state, Set $set): void {
                            $user = auth()->user();

                            $q = $user instanceof User
                                ? app(QuotationAuthorization::class)
                                    ->scope(Quotation::query(), $user)
                                    ->find($state)
                                : null;

                            if ($q) {
                                foreach ([
                                    'customer_name',
                                    'customer_company',
                                    'customer_phone',
                                    'customer_email',
                                    'customer_address',
                                    'customer_trn',
                                ] as $field) {
                                    $set($field, $q->{$field});
                                }
                            }
                        }),

                    TextInput::make('customer_name')
                        ->required()
                        ->maxLength(190),

                    TextInput::make('customer_company')
                        ->maxLength(190),

                    TextInput::make('customer_phone')
                        ->label('WhatsApp / Phone')
                        ->tel()
                        ->maxLength(40),

                    TextInput::make('customer_email')
                        ->email()
                        ->maxLength(190),

                    TextInput::make('customer_trn')
                        ->label('Customer TRN')
                        ->maxLength(50),

                    TextInput::make('external_reference')
                        ->maxLength(100),

                    Textarea::make('customer_address')
                        ->rows(3)
                        ->columnSpanFull(),

                    Textarea::make('notes')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Products')
                ->description('A Quotation does not reserve or deduct inventory.')
                ->schema([
                    Repeater::make('items')
                        ->schema([
                            Select::make('product_id')
                                ->label('Product')
                                ->placeholder('Search by SKU or product name')
                                ->required()
                                ->searchable()
                                ->searchDebounce(350)
                                ->searchPrompt('Type at least 2 characters to search Products.')
                                ->noSearchResultsMessage('No authorized Products match your search.')
                                ->getSearchResultsUsing(fn (string $search): array => app(ProductSearchOptions::class)->search(
                                    $search,
                                    ProductMatchContext::Quotation,
                                    auth()->user(),
                                    app(DefaultWarehouseService::class)->operationalDefault()->id,
                                ))
                                ->getOptionLabelUsing(
                                    fn ($value): ?string => ($p = Product::query()
                                        ->products()
                                        ->with('brandRelation:id,name,status')
                                        ->find($value, ['id', 'sku', 'name', 'brand', 'brand_id', 'model', 'inventory_item_type', 'status']))
                                            ? self::productLabel($p)
                                            : null
                                )
                                ->live()
                                ->afterStateUpdated(function ($state, Set $set): void {
                                    if ($product = Product::query()->products()->find($state, ['id', 'sku', 'name', 'selling_price'])) {
                                        $set('description', trim($product->sku.' · '.$product->name));
                                        $set('unit_price_including_vat', $product->selling_price);
                                        $set('vat_rate', '5.0000');
                                    }
                                })
                                ->helperText('Search is advisory. Quotations do not reserve or deduct stock.')
                                ->columnSpan(['default' => 1, 'md' => 5]),

                            TextInput::make('description')
                                ->required()
                                ->maxLength(500)
                                ->columnSpan(['default' => 1, 'md' => 5]),

                            TextInput::make('quantity')
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->default(1)
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(2),

                            TextInput::make('unit_price_including_vat')
                                ->label('Unit Price incl. VAT')
                                ->prefix('AED')
                                ->numeric()
                                ->minValue(.01)
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(3),

                            TextInput::make('discount_amount')
                                ->label('Discount')
                                ->prefix('AED')
                                ->numeric()
                                ->minValue(0)
                                ->default('0.00')
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(2),

                            TextInput::make('vat_rate')
                                ->label('VAT %')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(100)
                                ->default('5.0000')
                                ->required()
                                ->live(debounce: 300)
                                ->columnSpan(2),

                            Placeholder::make('line_total')
                                ->content(
                                    fn (Get $get): string => 'AED '.number_format(
                                        (float) (
                                            app(QuotationService::class)->preview([[
                                                'quantity' => $get('quantity') ?: 1,
                                                'unit_price_including_vat' => $get('unit_price_including_vat') ?: 0,
                                                'discount_amount' => $get('discount_amount') ?: 0,
                                                'vat_rate' => $get('vat_rate') ?: 5,
                                            ]])['grand'] ?? 0
                                        ),
                                        2
                                    )
                                )
                                ->columnSpan(2),
                        ])
                        ->columns(['default' => 1, 'md' => 12])
                        ->minItems(1)
                        ->maxItems(100)
                        ->defaultItems(1)
                        ->itemNumbers()
                        ->addActionLabel('+ Add Product'),
                ]),

            Section::make('Totals')
                ->schema([
                    Placeholder::make('totals')
                        ->hiddenLabel()
                        ->content(fn (Get $get): HtmlString => self::totals($get))
                        ->columnSpanFull(),
                ]),

            Hidden::make('idempotency_key')
                ->default(fn () => (string) str()->uuid()),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quotation')
                ->columns(4)
                ->schema([
                    TextEntry::make('reference'),

                    TextEntry::make('document_type')
                        ->formatStateUsing(fn ($state) => $state->label())
                        ->badge(),

                    TextEntry::make('status')
                        ->formatStateUsing(
                            fn ($state, Quotation $record) => $record->effectiveStatus()->label()
                        )
                        ->badge(),

                    TextEntry::make('grand_total')
                        ->money('AED')
                        ->weight('bold'),

                    TextEntry::make('quotation_date')
                        ->date('d M Y'),

                    TextEntry::make('valid_until')
                        ->date('d M Y'),

                    TextEntry::make('salesperson.name')
                        ->label('Salesperson'),

                    TextEntry::make('sent_at')
                        ->dateTime('d M Y, h:i A')
                        ->placeholder('—'),

                    TextEntry::make('customer_name'),

                    TextEntry::make('customer_company')
                        ->placeholder('—'),

                    TextEntry::make('customer_phone')
                        ->placeholder('—'),

                    TextEntry::make('customer_email')
                        ->placeholder('—'),

                    TextEntry::make('customer_address')
                        ->columnSpan(2)
                        ->placeholder('—'),

                    TextEntry::make('notes')
                        ->columnSpan(2)
                        ->placeholder('—'),

                    TextEntry::make('order.reference')
                        ->label('Linked Order')
                        ->placeholder('—')
                        ->url(
                            fn (Quotation $r) => $r->order
                                ? route('filament.admin.resources.orders.view', $r->order)
                                : null
                        ),

                    TextEntry::make('taxInvoice.invoice_number')
                        ->label('Linked Invoice')
                        ->placeholder('—')
                        ->url(
                            fn (Quotation $r) => $r->taxInvoice
                                ? route('filament.admin.resources.tax-invoices.view', $r->taxInvoice)
                                : null
                        ),
                ]),

            RepeatableEntry::make('items')
                ->schema([
                    TextEntry::make('sku'),
                    TextEntry::make('description'),
                    TextEntry::make('quantity'),
                    TextEntry::make('unit_price_including_vat')->money('AED'),
                    TextEntry::make('discount_amount')->money('AED'),
                    TextEntry::make('total_including_vat')->money('AED'),
                ])
                ->columns(6),

            RepeatableEntry::make('emailDeliveries')
                ->label('Email Delivery History')
                ->schema([
                    TextEntry::make('recipient_email'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('requested_at')->dateTime('d M Y, h:i A'),
                    TextEntry::make('sent_at')->dateTime('d M Y, h:i A')->placeholder('—'),
                ])
                ->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('document_type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),

                TextColumn::make('quotation_date')
                    ->label('Date')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('valid_until')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Customer')
                    ->searchable([
                        'customer_name',
                        'customer_company',
                        'customer_phone',
                        'customer_email',
                    ]),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(
                        fn ($state, Quotation $record) => $record->effectiveStatus()->label()
                    ),

                TextColumn::make('grand_total')
                    ->label('Total')
                    ->money('AED'),

                TextColumn::make('salesperson.name')
                    ->label('Salesperson'),

                TextColumn::make('order.reference')
                    ->label('Order')
                    ->placeholder('—'),

                TextColumn::make('taxInvoice.invoice_number')
                    ->label('Invoice')
                    ->placeholder('—'),

                TextColumn::make('sent_at')
                    ->dateTime('d M Y')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(QuotationStatus::options()),

                SelectFilter::make('document_type')
                    ->options(QuotationDocumentType::options()),

                SelectFilter::make('salesperson_employee_id')
                    ->label('Salesperson')
                    ->relationship('salesperson', 'name')
                    ->searchable()
                    ->preload(),

                TernaryFilter::make('converted')
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('converted_at'),
                        false: fn (Builder $query) => $query->whereNull('converted_at')
                    ),

                Filter::make('period')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('to'),
                    ])
                    ->query(
                        fn (Builder $query, array $data) => $query
                            ->when(
                                $data['from'] ?? null,
                                fn ($x, $v) => $x->whereDate('quotation_date', '>=', $v)
                            )
                            ->when(
                                $data['to'] ?? null,
                                fn ($x, $v) => $x->whereDate('quotation_date', '<=', $v)
                            )
                    ),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View'),

                ActionGroup::make([

                    EditAction::make()
                        ->label('Edit Draft')
                        ->icon('heroicon-o-pencil-square')
                        ->visible(
                            fn (Quotation $record): bool => $record->status === QuotationStatus::Draft
                                && self::canEdit($record)
                        ),

                    Action::make('pdf')
                        ->label('Download PDF')
                        ->icon('heroicon-o-arrow-down-tray')
                        ->url(
                            fn (Quotation $record): string => route('quotations.pdf', $record)
                        )
                        ->openUrlInNewTab()
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Export,
                                $record
                            )
                        ),

                    Action::make('print')
                        ->label('Print')
                        ->icon('heroicon-o-printer')
                        ->url(
                            fn (Quotation $record): string => route('quotations.pdf', [
                                'quotation' => $record,
                                'print' => 1,
                            ])
                        )
                        ->openUrlInNewTab()
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Export,
                                $record
                            )
                        ),

                    Action::make('email')
                        ->label('Send Email')
                        ->icon('heroicon-o-envelope')
                        ->schema([
                            TextInput::make('email')
                                ->label('Customer Email')
                                ->email()
                                ->required()
                                ->default(
                                    fn (Quotation $record) => $record->customer_email
                                ),

                            Hidden::make('idempotency_key')
                                ->default(
                                    fn () => (string) str()->uuid()
                                ),
                        ])
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Send,
                                $record
                            )
                                && in_array(
                                    $record->effectiveStatus(),
                                    [
                                        QuotationStatus::Draft,
                                        QuotationStatus::Sent,
                                    ],
                                    true
                                )
                        )
                        ->action(
                            function (
                                Quotation $record,
                                array $data
                            ): void {
                                app(QuotationEmailService::class)
                                    ->queue(
                                        $record,
                                        $data['email'],
                                        auth()->user(),
                                        $data['idempotency_key']
                                    );

                                Notification::make()
                                    ->success()
                                    ->title('Quotation email queued')
                                    ->send();
                            }
                        ),

                    Action::make('markSent')
                        ->label('Mark Sent')
                        ->icon('heroicon-o-paper-airplane')
                        ->requiresConfirmation()
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Send,
                                $record
                            )
                                && $record->effectiveStatus()
                                    === QuotationStatus::Draft
                        )
                        ->action(
                            function (Quotation $record): void {
                                app(QuotationService::class)
                                    ->transition(
                                        $record,
                                        QuotationStatus::Sent,
                                        auth()->user()
                                    );

                                Notification::make()
                                    ->success()
                                    ->title('Quotation marked as sent')
                                    ->send();
                            }
                        ),

                    Action::make('accept')
                        ->label('Accept')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Accept,
                                $record
                            )
                                && $record->effectiveStatus()
                                    === QuotationStatus::Sent
                        )
                        ->action(
                            function (Quotation $record): void {
                                app(QuotationService::class)
                                    ->transition(
                                        $record,
                                        QuotationStatus::Accepted,
                                        auth()->user()
                                    );

                                Notification::make()
                                    ->success()
                                    ->title('Quotation accepted')
                                    ->send();
                            }
                        ),

                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Reason')
                                ->required(),
                        ])
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Reject,
                                $record
                            )
                                && $record->effectiveStatus()
                                    === QuotationStatus::Sent
                        )
                        ->action(
                            function (
                                Quotation $record,
                                array $data
                            ): void {
                                app(QuotationService::class)
                                    ->transition(
                                        $record,
                                        QuotationStatus::Rejected,
                                        auth()->user(),
                                        $data['reason']
                                    );

                                Notification::make()
                                    ->success()
                                    ->title('Quotation rejected')
                                    ->send();
                            }
                        ),

                    Action::make('convertOrder')
                        ->label('Convert to Order')
                        ->icon('heroicon-o-shopping-cart')
                        ->schema([
                            Select::make('warehouse_id')
                                ->label('Fulfilment Location')
                                ->options(
                                    fn (): array => Warehouse::query()
                                        ->active()
                                        ->pluck('name', 'id')
                                        ->all()
                                )
                                ->searchable()
                                ->required(),
                        ])
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::ConvertOrder,
                                $record
                            )
                                && $record->effectiveStatus()
                                    === QuotationStatus::Accepted
                                && ! $record->order_id
                        )
                        ->action(
                            function (
                                Quotation $record,
                                array $data
                            ): void {
                                $order = app(
                                    QuotationConversionService::class
                                )->toOrder(
                                    $record,
                                    (int) $data['warehouse_id'],
                                    auth()->user()
                                );

                                Notification::make()
                                    ->success()
                                    ->title(
                                        "Converted to Order {$order->reference}"
                                    )
                                    ->send();
                            }
                        ),

                    Action::make('convertInvoice')
                        ->label('Convert to Tax Invoice')
                        ->icon('heroicon-o-document-currency-dollar')
                        ->requiresConfirmation()
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::ConvertInvoice,
                                $record
                            )
                                && $record->effectiveStatus()
                                    === QuotationStatus::Accepted
                                && ! $record->tax_invoice_id
                        )
                        ->action(
                            function (Quotation $record): void {
                                $invoice = app(
                                    QuotationConversionService::class
                                )->toInvoice(
                                    $record,
                                    auth()->user()
                                );

                                Notification::make()
                                    ->success()
                                    ->title(
                                        "Converted to Invoice {$invoice->invoice_number}"
                                    )
                                    ->send();
                            }
                        ),

                    Action::make('openOrder')
                        ->label(
                            fn (Quotation $record): string => $record->order
                                    ? 'Open Order '.$record->order->reference
                                    : 'Open Order'
                        )
                        ->icon('heroicon-o-shopping-bag')
                        ->url(
                            fn (Quotation $record): string => route(
                                'filament.admin.resources.orders.view',
                                $record->order
                            )
                        )
                        ->visible(
                            fn (Quotation $record): bool => $record->order_id !== null
                        ),

                    Action::make('openInvoice')
                        ->label(
                            fn (Quotation $record): string => $record->taxInvoice
                                    ? 'Open Invoice '
                                        .$record->taxInvoice->invoice_number
                                    : 'Open Invoice'
                        )
                        ->icon('heroicon-o-document-text')
                        ->url(
                            fn (Quotation $record): string => route(
                                'filament.admin.resources.tax-invoices.view',
                                $record->taxInvoice
                            )
                        )
                        ->visible(
                            fn (Quotation $record): bool => $record->tax_invoice_id !== null
                        ),

                    Action::make('cancel')
                        ->label('Cancel Quotation')
                        ->icon('heroicon-o-no-symbol')
                        ->color('danger')
                        ->schema([
                            Textarea::make('reason')
                                ->label('Cancellation Reason')
                                ->required(),
                        ])
                        ->visible(
                            fn (Quotation $record): bool => self::allowed(
                                QuotationPermission::Cancel,
                                $record
                            )
                                && in_array(
                                    $record->effectiveStatus(),
                                    [
                                        QuotationStatus::Draft,
                                        QuotationStatus::Sent,
                                        QuotationStatus::Accepted,
                                    ],
                                    true
                                )
                        )
                        ->action(
                            function (
                                Quotation $record,
                                array $data
                            ): void {
                                app(QuotationService::class)
                                    ->transition(
                                        $record,
                                        QuotationStatus::Cancelled,
                                        auth()->user(),
                                        $data['reason']
                                    );

                                Notification::make()
                                    ->success()
                                    ->title('Quotation cancelled')
                                    ->send();
                            }
                        ),

                ])
                    ->label('Actions')
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->button(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(QuotationAuthorization::class)
                ->scope(parent::getEloquentQuery(), $user)
                ->with([
                    'items',
                    'salesperson',
                    'order',
                    'taxInvoice',
                    'emailDeliveries',
                ])
            : parent::getEloquentQuery()->whereRaw('1=0');
    }

    public static function canViewAny(): bool
    {
        return self::allowed(QuotationPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return self::allowed(QuotationPermission::Create);
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof Quotation
            && self::allowed(QuotationPermission::View, $record);
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof Quotation
            && $record->status === QuotationStatus::Draft
            && self::allowed(QuotationPermission::Update, $record);
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }

    public static function allowed(
        QuotationPermission $permission,
        ?Quotation $record = null
    ): bool {
        $u = auth()->user();

        return $u instanceof User
            && app(QuotationAuthorization::class)->allows($u, $permission, $record);
    }

    private static function productLabel(Product $p): string
    {
        return $p->sku
            .' · '
            .str($p->name)->limit(70)
            .' · '
            .$p->displayBrandName()
            .($p->model ? ' · '.$p->model : '');
    }

    private static function totals(Get $get): HtmlString
    {
        $t = app(QuotationService::class)->preview(
            (array) ($get('items') ?? [])
        );

        return new HtmlString(
            '<div class="ml-auto max-w-xl rounded-xl border p-4 dark:border-white/10">'
            .'<div>Subtotal excl. VAT: <strong>AED '
            .number_format((float) ($t['subtotal'] ?? 0), 2)
            .'</strong></div>'
            .'<div>Discount: <strong>AED '
            .number_format((float) ($t['discount'] ?? 0), 2)
            .'</strong></div>'
            .'<div>VAT: <strong>AED '
            .number_format((float) ($t['vat'] ?? 0), 2)
            .'</strong></div>'
            .'<div class="mt-2 text-lg">Grand Total: <strong>AED '
            .number_format((float) ($t['grand'] ?? 0), 2)
            .'</strong></div>'
            .'</div>'
        );
    }
}
