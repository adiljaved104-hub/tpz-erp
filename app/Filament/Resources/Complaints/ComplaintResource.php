<?php

namespace App\Filament\Resources\Complaints;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Exceptions\ComplaintException;
use App\Filament\Resources\Complaints\Pages\CreateComplaint;
use App\Filament\Resources\Complaints\Pages\ListComplaints;
use App\Filament\Resources\Complaints\Pages\ViewComplaint;
use App\Models\Complaint;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use App\Services\ServiceCases\ServiceCaseOrderContextService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Hidden;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ComplaintResource extends Resource
{
    protected static ?string $model = Complaint::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|\UnitEnum|null $navigationGroup = 'Service';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('complaint.view') === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('complaint.create') === true;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->can('complaint.view', $record) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        return $user ? app(ComplaintAuthorization::class)->scopeQuery($query, $user) : $query->whereRaw('1 = 0');
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return Complaint::query();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Complaint')->columns(2)->schema([
                Select::make('order_id')->label('Order / Marketplace Order ID')->searchable()->live()
                    ->getSearchResultsUsing(fn (string $search): array => app(ServiceCaseOrderContextService::class)->searchOrders($search))
                    ->getOptionLabelUsing(fn ($value): ?string => filled($value) ? (($order = app(ServiceCaseOrderContextService::class)->findOrder((int) $value)) ? app(ServiceCaseOrderContextService::class)->orderLabel($order) : null) : null)
                    ->afterStateUpdated(function ($state, Set $set): void {
                        if (blank($state)) {
                            foreach (['marketplace_platform_id', 'product_id', 'customer_return_id', 'warranty_repair_id'] as $field) {
                                $set($field, null);
                            }
                            $set('quantity', 1);

                            return;
                        }
                        $context = app(ServiceCaseOrderContextService::class)->context((int) $state);
                        $set('marketplace_platform_id', $context['platform_id']);
                        $set('product_id', $context['product_id']);
                        $set('quantity', $context['quantity']);
                        $set('customer_return_id', $context['customer_return_id']);
                        $set('warranty_repair_id', $context['warranty_repair_id']);
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
                            null,
                        ))
                    ->getOptionLabelUsing(fn ($value, Get $get): ?string => filled($get('order_id'))
                        ? (app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id'))[(int) $value] ?? null)
                        : app(ServiceCaseOrderContextService::class)->selectedProductLabel(
                            (int) $value,
                            filled($get('marketplace_platform_id')) ? (int) $get('marketplace_platform_id') : null,
                            null,
                        ))
                    ->searchPrompt('Type at least 2 characters to search authorized Products.')
                    ->noSearchResultsMessage('No authorized Products found.')
                    ->searchable()->live()
                    ->disabled(fn (Get $get): bool => filled($get('order_id')) && count(app(ServiceCaseOrderContextService::class)->orderProducts((int) $get('order_id'))) === 1)
                    ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                        if (filled($get('order_id')) && filled($state)) {
                            $set('quantity', app(ServiceCaseOrderContextService::class)->productQuantity((int) $get('order_id'), (int) $state));
                            $set('warranty_repair_id', app(ServiceCaseOrderContextService::class)->warrantyId((int) $get('order_id'), (int) $state));
                        }
                    })
                    ->dehydrated()->required(),
                Select::make('marketplace_platform_id')->label('Platform')->options(fn (): array => app(ServiceCaseOrderContextService::class)->platformOptions())->searchable()->preload()->disabled(fn (Get $get): bool => filled($get('order_id')))->dehydrated(),
                TextInput::make('quantity')->numeric()->default(1)->minValue(1),
                Select::make('category')->options(ComplaintCategory::class)->required(),
                Textarea::make('description')->columnSpanFull()->required(),
                Hidden::make('customer_return_id'),
                Hidden::make('warranty_repair_id'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('reference')->searchable(), TextColumn::make('category')->badge(), TextColumn::make('product.name')->label('Product')->placeholder('General'), TextColumn::make('platform.name')->label('Platform')->placeholder('Internal'), TextColumn::make('status')->badge(), SelectColumn::make('assigned_to_user_id')->label('Assigned To')->placeholder(fn (Complaint $record): string => app(ServiceCaseAssigneeService::class)->complaintPlaceholder($record))->options(fn (?Complaint $record) => $record ? app(ServiceCaseAssigneeService::class)->complaintOptions($record) : [])->updateStateUsing(fn (Complaint $record, $state) => app(ServiceCaseAssigneeService::class)->assignComplaint($record, filled($state) ? (int) $state : null, auth()->user())->assigned_to_user_id), TextColumn::make('opened_at')->date('d M Y'), TextColumn::make('days_open')->state(function (Complaint $record): string {
            $days = (int) $record->opened_at->startOfDay()->diffInDays(($record->resolved_at ?? now())->startOfDay());

            return $days.' '.str('day')->plural($days);
        })->label('Days Open')])->filters([
            SelectFilter::make('status')->options(ComplaintStatus::class),
            SelectFilter::make('ownership')->label('Assignment')->options(['assigned_to_me' => 'Assigned to Me', 'unassigned' => 'Unassigned'])->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'assigned_to_me' => $query->where('assigned_to_user_id', auth()->id()),
                    'unassigned' => $query->whereNull('assigned_to_user_id'),
                    default => $query,
                };
            }),
        ])->recordActions([
            Action::make('changeStatus')->label('Update Status')->schema([
                Select::make('status')->options(ComplaintStatus::class)->live()->required(),
                Select::make('resolution')->options(ComplaintResolution::class)->visible(fn (Get $get): bool => self::statusValue($get('status')) === ComplaintStatus::Resolved->value)->required(fn (Get $get): bool => self::statusValue($get('status')) === ComplaintStatus::Resolved->value),
                Textarea::make('note')->label(fn (Get $get): string => self::statusValue($get('status')) === ComplaintStatus::Cancelled->value ? 'Cancellation Reason' : 'Note')->required(fn (Get $get): bool => self::statusValue($get('status')) === ComplaintStatus::Cancelled->value),
            ])->action(function (Complaint $record, array $data): void {
                try {
                    $status = $data['status'] instanceof ComplaintStatus ? $data['status'] : ComplaintStatus::from($data['status']);
                    $resolution = filled($data['resolution'] ?? null) ? ($data['resolution'] instanceof ComplaintResolution ? $data['resolution'] : ComplaintResolution::from($data['resolution'])) : null;
                    app(ComplaintService::class)->transition($record, $status, auth()->user(), $resolution, $data['note'] ?? null);
                    Notification::make()->success()->title('Complaint updated')->send();
                } catch (ComplaintException|ValidationException|AuthorizationException $exception) {
                    Notification::make()->danger()->title('Complaint could not be updated')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to update this Complaint.'))->send();
                }
            }),
            ViewAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListComplaints::route('/'), 'create' => CreateComplaint::route('/create'), 'view' => ViewComplaint::route('/{record}')];
    }

    private static function statusValue(mixed $status): ?string
    {
        return $status instanceof ComplaintStatus ? $status->value : $status;
    }
}
