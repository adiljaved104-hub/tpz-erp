<?php

namespace App\Filament\Resources\InternalRepairs;

use App\Enums\WarrantyRepairStatus;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Pages\Inventory\DamagedItems;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\Pages\EditInternalRepair;
use App\Filament\Resources\InternalRepairs\Pages\ListInternalRepairs;
use App\Filament\Resources\InternalRepairs\Pages\ViewInternalRepair;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\WarrantyRepair;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use App\Services\ServiceCases\WarrantyRepairLifecycleService;
use App\Services\ServiceCases\WarrantyRepairService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
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
use Illuminate\Validation\ValidationException;

class InternalRepairResource extends Resource
{
    protected static ?string $model = WarrantyRepair::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrench;

    protected static string|\UnitEnum|null $navigationGroup = 'Service';

    protected static ?string $navigationLabel = 'Internal Repairs';

    protected static ?string $modelLabel = 'Internal Repair';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('warranty_repair.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return $record instanceof WarrantyRepair && $record->isInternalCompanyOwnedRepair()
            && auth()->user()?->can('warranty_repair.update_status', $record) === true;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof WarrantyRepair && $record->isInternalCompanyOwnedRepair()
            && auth()->user()?->can('warranty_repair.view', $record) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user
            ? app(WarrantyRepairAuthorization::class)->scopeQuery(parent::getEloquentQuery(), $user)
                ->internalCompanyOwned()
                ->with(['product:id,sku,name', 'damagedStockEvent:id,reference,source,customer_return_id,order_id', 'damagedStockEvent.customerReturn:id,reference', 'damagedStockEvent.order:id,reference', 'assignedTo:id,name', 'sentToTechnicianEvent'])
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return WarrantyRepair::query()->internalCompanyOwned();
    }

    public static function form(Schema $schema): Schema
    {
        return WarrantyRepairResource::form($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return Schemas\InternalRepairInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->label('Repair Ref')->searchable()->url(fn (WarrantyRepair $record): string => self::getUrl('view', ['record' => $record])),
                TextColumn::make('product.sku')->label('SKU')->searchable(),
                TextColumn::make('product.name')->label('Product')->searchable(),
                TextColumn::make('quantity')->label('Qty')->numeric(),
                TextColumn::make('damagedStockEvent.source')->label('Original Damage Source')
                    ->state(fn (WarrantyRepair $record): string => $record->isLegacyDamagedRepair() ? 'Old Damaged Stock' : ($record->damagedStockEvent?->source?->getLabel() ?? 'Unknown'))
                    ->badge(),
                TextColumn::make('damagedStockEvent.reference')->label('Damaged Ref')->placeholder('—')
                    ->url(fn (WarrantyRepair $record): ?string => $record->damagedStockEvent?->reference ? DamagedItems::getUrl(['search' => $record->damagedStockEvent->reference]) : null),
                TextInputColumn::make('service_provider')->label('Technician / Service Provider')
                    ->updateStateUsing(fn (WarrantyRepair $record, $state) => app(WarrantyRepairService::class)->updateOperationalDetails($record, ['service_provider' => $state], auth()->user())->service_provider),
                TextColumn::make('status')->badge(),
                TextColumn::make('sentToTechnicianEvent.changed_at')->label('Sent Date')->date('d M Y')->placeholder('—'),
                TextInputColumn::make('expected_return_at')->label('Expected Return')->type('date')
                    ->updateStateUsing(fn (WarrantyRepair $record, $state) => app(WarrantyRepairService::class)->updateOperationalDetails($record, ['expected_return_at' => $state], auth()->user())->expected_return_at?->format('Y-m-d')),
                TextColumn::make('days_with_technician')->label('Days With Technician')->state(function (WarrantyRepair $record): string {
                    $sentAt = $record->sentToTechnicianEvent?->changed_at ?? $record->sent_to_technician_at;
                    if ($sentAt === null) {
                        return '—';
                    }
                    $days = (int) $sentAt->startOfDay()->diffInDays(($record->received_back_at ?? now())->startOfDay());

                    return $days.' '.str('day')->plural($days);
                }),
                SelectColumn::make('assigned_to_user_id')->label('Assigned To')->placeholder(fn (WarrantyRepair $record): string => app(ServiceCaseAssigneeService::class)->warrantyPlaceholder($record))
                    ->options(fn (?WarrantyRepair $record) => $record ? app(ServiceCaseAssigneeService::class)->warrantyOptions($record) : [])
                    ->updateStateUsing(fn (WarrantyRepair $record, $state) => app(ServiceCaseAssigneeService::class)->assignWarranty($record, filled($state) ? (int) $state : null, auth()->user())->assigned_to_user_id),
                TextColumn::make('context')->label('Order / Return')->state(fn (WarrantyRepair $record): string => $record->damagedStockEvent?->customerReturn?->reference ?? $record->damagedStockEvent?->order?->reference ?? '—')
                    ->url(function (WarrantyRepair $record): ?string {
                        if ($record->damagedStockEvent?->customerReturn) {
                            return CustomerReturnResource::getUrl('view', ['record' => $record->damagedStockEvent->customerReturn]);
                        }
                        if ($record->damagedStockEvent?->order) {
                            return OrderResource::getUrl('view', ['record' => $record->damagedStockEvent->order]);
                        }

                        return null;
                    }),
            ])
            ->filters([
                SelectFilter::make('status')->options(WarrantyRepairStatus::class),
                SelectFilter::make('ownership')->label('Assignment')->options(['assigned_to_me' => 'Assigned to Me', 'unassigned' => 'Unassigned'])->query(function (Builder $query, array $data): Builder {
                    return match ($data['value'] ?? null) {
                        'assigned_to_me' => $query->where('assigned_to_user_id', auth()->id()),
                        'unassigned' => $query->whereNull('assigned_to_user_id'),
                        default => $query,
                    };
                }),
            ])
            ->recordActions([...self::guidedActions(), ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInternalRepairs::route('/'),
            'view' => ViewInternalRepair::route('/{record}'),
            'edit' => EditInternalRepair::route('/{record}/edit'),
        ];
    }

    /** @return list<Action> */
    private static function guidedActions(): array
    {
        return collect(WarrantyRepairStatus::cases())
            ->filter(fn (WarrantyRepairStatus $status): bool => ! in_array($status, [WarrantyRepairStatus::Received, WarrantyRepairStatus::InspectionPending, WarrantyRepairStatus::UnderInspection, WarrantyRepairStatus::DispatchedBack, WarrantyRepairStatus::Cancelled], true))
            ->map(function (WarrantyRepairStatus $status): Action {
                $action = Action::make('next_'.$status->value)
                    ->label(fn (WarrantyRepair $record): string => app(WarrantyRepairLifecycleService::class)->actionLabel($status, $record))
                    ->visible(fn (WarrantyRepair $record): bool => app(WarrantyRepairLifecycleService::class)->allows($record, $status) && auth()->user()->can('warranty_repair.update_status', $record));

                if ($status === WarrantyRepairStatus::CannotRepair) {
                    $action->schema([Textarea::make('note')->label(fn (WarrantyRepair $record): string => $record->status === WarrantyRepairStatus::QcPending ? 'QC Failure Reason' : 'Reason')->required()->maxLength(2000)]);
                } elseif ($status === WarrantyRepairStatus::WaitingForParts) {
                    $action->schema([Textarea::make('note')->label('Note')->maxLength(2000)]);
                }

                return $action->action(fn (WarrantyRepair $record, array $data): mixed => self::runTransition($record, $status, $data));
            })->values()->all();
    }

    private static function runTransition(WarrantyRepair $record, WarrantyRepairStatus $status, array $data): mixed
    {
        try {
            app(WarrantyRepairService::class)->transition($record, $status, auth()->user(), $data['note'] ?? null);
            Notification::make()->success()->title('Internal Repair updated')->send();
        } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
            Notification::make()->danger()->title('Internal Repair could not be updated')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this repair.'))->send();
        }

        return null;
    }
}
