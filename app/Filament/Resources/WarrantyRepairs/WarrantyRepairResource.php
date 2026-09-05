<?php

namespace App\Filament\Resources\WarrantyRepairs;

use App\Enums\CustomerReturnPermission;
use App\Enums\WarrantyRepairSource;
use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\WarrantyRepairs\Pages\CreateWarrantyRepair;
use App\Filament\Resources\WarrantyRepairs\Pages\EditWarrantyRepair;
use App\Filament\Resources\WarrantyRepairs\Pages\ListWarrantyRepairs;
use App\Filament\Resources\WarrantyRepairs\Pages\ViewWarrantyRepair;
use App\Filament\Resources\WarrantyRepairs\Schemas\WarrantyRepairInfolist;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use App\Services\ServiceCases\ServiceCaseOrderContextService;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use App\Services\ServiceCases\WarrantySlaService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarrantyRepairResource extends Resource
{
    protected static ?string $model = WarrantyRepair::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|\UnitEnum|null $navigationGroup = 'Service';

    protected static ?string $navigationLabel = 'Warranty / Service';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('warranty_repair.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('warranty_repair.create') === true;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('warranty_repair.update_status', $record) === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('warranty_repair.view', $record) === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return WarrantyRepairInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        $financial = app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::ViewRefundAmount);
        $refundFields = ['id', 'customer_return_id', 'warranty_repair_id', 'return_type', 'status', 'refund_date'];
        $claimFields = ['id', 'customer_return_id', 'reference', 'status'];
        if ($financial) {
            array_push($refundFields, 'refund_amount', 'currency');
            array_push($claimFields, 'reimbursed_amount', 'paid_at');
        }

        return app(WarrantyRepairAuthorization::class)->scopeQuery($query, $user)->externalService()->with([
            'customerReturn:id,reference',
            'refund' => fn ($refund) => $refund->select($refundFields),
            'customerReturn.refund' => fn ($refund) => $refund->select($refundFields),
            'customerReturn.claims' => fn ($claims) => $claims->select($claimFields),
        ]);
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return WarrantyRepair::query()->externalService();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Receive Service Item')->columns(2)->visibleOn('create')->schema([
                Select::make('order_id')->label('Order / Marketplace Order ID')->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search): array => app(ServiceCaseOrderContextService::class)->searchOrders($search))
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (($order = app(ServiceCaseOrderContextService::class)->findOrder((int) $value)) ? app(ServiceCaseOrderContextService::class)->orderLabel($order) : null) : null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (blank($state)) {
                            foreach (['marketplace_platform_id', 'product_id', 'customer_return_id'] as $field) {
                                $set($field, null);
                            }
                            $set('quantity', 1);

                            return;
                        }
                        $context = app(ServiceCaseOrderContextService::class)->context((int) $state);
                        $set('marketplace_platform_id', $context['platform_id']);
                        $set('warehouse_id', $context['warehouse_id']);
                        $set('product_id', $context['product_id']);
                        $set('quantity', $context['quantity']);
                        $set('customer_return_id', $context['customer_return_id']);
                    })->helperText('Search by internal Order reference or marketplace Order ID.'),
                Select::make('product_id')->label('Affected Item')
                    ->options(fn (Get $get): array => filled($get('order_id'))
                        ? app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id'))
                        : [])
                    ->getSearchResultsUsing(fn (string $search, Get $get): array => filled($get('order_id'))
                        ? collect(app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id')))
                            ->filter(fn (string $label): bool => str_contains(strtolower($label), strtolower(trim($search))))->all()
                        : app(ServiceCaseOrderContextService::class)->searchProducts(
                            $search,
                            filled($get('marketplace_platform_id')) ? (int) $get('marketplace_platform_id') : null,
                            filled($get('warehouse_id')) ? (int) $get('warehouse_id') : null,
                        ))
                    ->getOptionLabelUsing(fn ($value, Get $get): ?string => filled($get('order_id'))
                        ? (app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id'))[(int) $value] ?? null)
                        : app(ServiceCaseOrderContextService::class)->selectedProductLabel(
                            (int) $value,
                            filled($get('marketplace_platform_id')) ? (int) $get('marketplace_platform_id') : null,
                            filled($get('warehouse_id')) ? (int) $get('warehouse_id') : null,
                        ))
                    ->searchPrompt('Type at least 2 characters to search authorized Products.')
                    ->noSearchResultsMessage('No authorized Products found.')
                    ->searchable()->live()
                    ->disabled(fn (Get $get): bool => filled($get('order_id')) && count(app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id'))) === 1)
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        if (filled($get('order_id')) && filled($state)) {
                            $set('quantity', app(ServiceCaseOrderContextService::class)->productQuantity((int) $get('order_id'), (int) $state));
                        }
                    })
                    ->dehydrated()->required(),
                Select::make('marketplace_platform_id')->label('Platform')->options(fn (): array => app(ServiceCaseOrderContextService::class)->platformOptions())->searchable()->preload()->live()->disabled(fn (Get $get): bool => filled($get('order_id')))->dehydrated(),
                Select::make('warehouse_id')->label('Location')->relationship('warehouse', 'name')->searchable()->preload()->live()->required(),
                TextInput::make('quantity')->numeric()->default(1)->minValue(1)->required(),
                TextInput::make('serial_number')->maxLength(255),
                TextInput::make('received_from')->maxLength(255),
                Textarea::make('issue_description')->columnSpanFull()->required(),
                DateTimePicker::make('received_at')->default(now())->maxDate(now())->live()->required()->validationMessages(['before_or_equal' => 'Received date cannot be in the future.']),
                Placeholder::make('sla_due_display')->label('SLA Due')->content(fn (Get $get): string => filled($get('received_at')) ? app(WarrantySlaService::class)->dueFrom($get('received_at'))->format('d-M-Y h:i:s A') : 'Select Received At.'),
                DateTimePicker::make('expected_return_at')->label('Expected Return')->helperText('Internal estimate only. SLA Due remains unchanged.'),
                Textarea::make('notes')->columnSpanFull(),
                Hidden::make('customer_return_id'),
            ]),
            Section::make('Operational Details')->columns(2)->visibleOn('edit')->schema([
                Placeholder::make('received_at_display')->label('Received At')->content(fn (?WarrantyRepair $record): string => $record?->received_at?->format('d-M-Y h:i:s A') ?? '—')->helperText('Received At is immutable after creation.'),
                Placeholder::make('sla_due_display_edit')->label('SLA Due')->content(fn (?WarrantyRepair $record): string => $record ? app(WarrantySlaService::class)->dueAt($record)->format('d-M-Y h:i:s A') : '—'),
                DateTimePicker::make('expected_return_at')->label('Expected Return')->helperText('Internal estimate only. Must not be before Received At.'),
                TextInput::make('service_provider')->label('Technician / Service Provider')->maxLength(255),
                TextInput::make('external_service_reference')->label('External Item / Service Ref')->maxLength(255),
                TextInput::make('serial_number')->label('Serial Number')->maxLength(255),
                TextInput::make('received_from')->label('Received From')->maxLength(255),
                Textarea::make('issue_description')->label('Issue Description')->required()->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable(),
            TextColumn::make('product.name')->label('Product'),
            TextColumn::make('platform.name')->label('Platform')->placeholder('Internal / Manual'),
            TextColumn::make('status')->badge(),
            TextColumn::make('moved_to_damaged_at')->label('Damaged Items')->state(fn (WarrantyRepair $record): string => $record->moved_to_damaged_at === null ? 'Not moved' : 'Moved to Damaged')->badge()->color(fn (string $state): string => $state === 'Moved to Damaged' ? 'success' : 'gray'),
            SelectColumn::make('assigned_to_user_id')->label('Assigned To')->placeholder(fn (WarrantyRepair $record): string => app(ServiceCaseAssigneeService::class)->warrantyPlaceholder($record))->options(fn (?WarrantyRepair $record) => $record ? app(ServiceCaseAssigneeService::class)->warrantyOptions($record) : [])->updateStateUsing(fn (WarrantyRepair $record, $state) => app(ServiceCaseAssigneeService::class)->assignWarranty($record, filled($state) ? (int) $state : null, auth()->user())->assigned_to_user_id),
            TextInputColumn::make('service_provider')->label('Technician / Service Provider')->updateStateUsing(fn (WarrantyRepair $record, $state) => app(WarrantyRepairService::class)->updateOperationalDetails($record, ['service_provider' => $state], auth()->user())->service_provider),
            TextInputColumn::make('external_service_reference')->label('External Item / Service Ref')->updateStateUsing(fn (WarrantyRepair $record, $state) => app(WarrantyRepairService::class)->updateOperationalDetails($record, ['external_service_reference' => $state], auth()->user())->external_service_reference),
            TextColumn::make('received_at')->label('Received')->date('d M Y'),
            TextColumn::make('sla_due')->label('SLA Due')->state(fn (WarrantyRepair $record) => app(WarrantySlaService::class)->dueAt($record))->date('d M Y'),
            TextColumn::make('sla_days_left')->label('Days Left')->state(fn (WarrantyRepair $record): string => app(WarrantySlaService::class)->daysLeftLabel($record)),
            TextColumn::make('sla_status')->label('SLA Status')->state(fn (WarrantyRepair $record): string => app(WarrantySlaService::class)->status($record))->badge(),
            TextInputColumn::make('expected_return_at')->label('Expected Return')->type('date')->updateStateUsing(fn (WarrantyRepair $record, $state) => app(WarrantyRepairService::class)->updateOperationalDetails($record, ['expected_return_at' => $state], auth()->user())->expected_return_at?->format('Y-m-d')),
            TextColumn::make('lastStatusEvent.changed_at')->label('Last Status Change')->dateTime('d M Y, h:i A')->placeholder('—'),
            TextColumn::make('lastStatusEvent.changedBy.name')->label('Changed By')->placeholder('—'),
            TextColumn::make('dispatched_back_at')->label('Dispatch Date')->dateTime('d M Y, h:i A')->placeholder('—'),
            TextColumn::make('days_open')->state(function (WarrantyRepair $record): string {
                $days = max(0, (int) $record->received_at->startOfDay()->diffInDays(($record->completed_at ?? now())->startOfDay()));

                return $days.' '.str('day')->plural($days);
            })->label('Days Open'),
        ])->filters([
            SelectFilter::make('status')->options(WarrantyRepairStatus::class),
            SelectFilter::make('ownership')->label('Assignment')->options(['assigned_to_me' => 'Assigned to Me', 'unassigned' => 'Unassigned'])->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'assigned_to_me' => $query->where('assigned_to_user_id', auth()->id()),
                    'unassigned' => $query->whereNull('assigned_to_user_id'),
                    default => $query,
                };
            }),
        ])->recordActions([...self::guidedActions(), self::moveToDamagedAction(), ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListWarrantyRepairs::route('/'), 'create' => CreateWarrantyRepair::route('/create'), 'view' => ViewWarrantyRepair::route('/{record}'), 'edit' => EditWarrantyRepair::route('/{record}/edit')];
    }

    /** @return list<Action> */
    private static function guidedActions(): array
    {
        return collect(WarrantyRepairStatus::cases())
            ->filter(fn (WarrantyRepairStatus $status): bool => ! in_array($status, [WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending, WarrantyRepairStatus::Cancelled], true))
            ->map(function (WarrantyRepairStatus $status): Action {
                $action = Action::make('next_'.$status->value)
                    ->label(fn (WarrantyRepair $record): string => app(WarrantyRepairLifecycleService::class)->actionLabel($status, $record))
                    ->visible(fn (WarrantyRepair $record): bool => app(WarrantyRepairLifecycleService::class)->allows($record, $status) && auth()->user()->can('warranty_repair.update_status', $record));

                if ($status === WarrantyRepairStatus::CannotRepair) {
                    $action->schema([Textarea::make('note')->label('Reason')->required()->maxLength(2000)]);
                } elseif ($status === WarrantyRepairStatus::WaitingForParts) {
                    $action->schema([Textarea::make('note')->label('Note')->maxLength(2000)]);
                } elseif ($status === WarrantyRepairStatus::DispatchedBack) {
                    $action->schema([
                        DateTimePicker::make('dispatched_back_at')->label('Dispatch Date')->default(now())->maxDate(now())->validationMessages(['before_or_equal' => 'Dispatch date cannot be in the future.'])->required(),
                        TextInput::make('dispatch_tracking_reference')->label('Tracking / Dispatch Reference')->maxLength(255),
                        Textarea::make('note')->label('Note')->maxLength(2000),
                    ]);
                }

                return $action->action(fn (WarrantyRepair $record, array $data): mixed => self::runGuidedTransition($record, $status, $data));
            })->values()->all();
    }

    private static function moveToDamagedAction(): Action
    {
        return Action::make('moveToDamaged')->label('Move to Damaged Items')->color('danger')->requiresConfirmation()
            ->visible(fn (WarrantyRepair $record): bool => $record->status === WarrantyRepairStatus::CannotRepair && $record->moved_to_damaged_at === null && $record->source !== WarrantyRepairSource::DamagedItem && auth()->user()->can('warranty_repair.move_to_damaged', $record))
            ->schema([TextInput::make('quantity')->numeric()->minValue(1)->default(fn (WarrantyRepair $record): int => $record->quantity)->required(), Select::make('warehouse_id')->label('Company Location')->relationship('warehouse', 'name')->default(fn (WarrantyRepair $record): int => $record->warehouse_id)->required(), Textarea::make('reason')->required(), Textarea::make('note')])
            ->action(function (WarrantyRepair $record, array $data): void {
                try {
                    app(WarrantyRepairService::class)->moveToDamaged($record, (int) $data['quantity'], (int) $data['warehouse_id'], $data['reason'], $data['note'] ?? null, (string) Str::uuid(), auth()->user());
                    Notification::make()->success()->title('Moved to Damaged Items')->send();
                } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
                    Notification::make()->danger()->title('Cannot move to Damaged Items')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this case.'))->send();
                }
            });
    }

    private static function runGuidedTransition(WarrantyRepair $record, WarrantyRepairStatus $status, array $data): mixed
    {
        try {
            $fields = $status === WarrantyRepairStatus::DispatchedBack ? ['dispatched_back_at' => $data['dispatched_back_at'], 'dispatch_tracking_reference' => $data['dispatch_tracking_reference'] ?? null] : [];
            app(WarrantyRepairService::class)->transition($record, $status, auth()->user(), $data['note'] ?? null, $fields);
            Notification::make()->success()->title('Warranty / Repair updated')->send();
        } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Warranty / Repair could not be updated')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this case.'))->send();
        }

        return null;
    }
}
