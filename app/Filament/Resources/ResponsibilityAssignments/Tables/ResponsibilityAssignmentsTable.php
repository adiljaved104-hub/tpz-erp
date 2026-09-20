<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Tables;

use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignments;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Models\Employee;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAllocationService;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ResponsibilityAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('employee.name')->label('Employee')->searchable()->sortable()->description(fn (ResponsibilityAssignment $record): ?string => $record->employee?->employee_id),
            TextColumn::make('team_name_at_assignment')->label('Team')->placeholder('—'),
            TextColumn::make('brandScope.brand.name')->label('Brand')->placeholder('—'),
            TextColumn::make('categoryScope.category.name')->label('Category')->placeholder('—'),
            TextColumn::make('conditionScope.product_condition')->label('Condition')->formatStateUsing(fn ($state): string => $state?->label() ?? '—')->placeholder('—'),
            TextColumn::make('platformScope.platform.name')->label('Platform')->placeholder('—'),
            TextColumn::make('product_display')->label('Product')->state(fn (ResponsibilityAssignment $record): ?string => $record->productScope?->product?->name ?? $record->quantityScope?->inventory?->product?->name)->placeholder('—')->wrap(),
            TextColumn::make('warehouse_display')->label('Warehouse')->state(fn (ResponsibilityAssignment $record): ?string => $record->warehouseScope?->warehouse?->name ?? $record->quantityScope?->inventory?->warehouse?->name)->placeholder('—'),
            TextColumn::make('quantityScope.assigned_quantity')->label('Assigned Qty')->placeholder('—'),
            TextColumn::make('allocation_remaining')->label('Allocation Remaining')->state(fn (ResponsibilityAssignment $record): ?int => $record->quantityScope === null ? null : app(ResponsibilityAllocationService::class)->usage($record)['remaining'])->placeholder('—'),
            TextColumn::make('physical_sellable')->label('Physical Sellable')->placeholder('—'),
            TextColumn::make('capacity_summary')->label('Capacity')->state(function (ResponsibilityAssignment $record): string {
                if ($record->quantityScope === null) {
                    return 'Not Applicable';
                }
                $summary = app(ResponsibilityCapacityService::class)->summary($record->quantityScope->product_inventory_id);

                return "{$summary['status']->getLabel()} · Outstanding {$summary['outstanding']} · Assignable {$summary['remaining']}";
            })->wrap(),
            TextColumn::make('effective_at')->dateTime('d M Y, h:i A')->sortable(),
            TextColumn::make('ended_at')->dateTime('d M Y, h:i A')->placeholder('Active'),
            TextColumn::make('status')->badge(),
            TextColumn::make('assignedBy.name')->label('Assigned By'),
            TextColumn::make('reason')->limit(40)->tooltip(fn (ResponsibilityAssignment $record): string => $record->reason),
        ])->filters([
            SelectFilter::make('status')->options(ResponsibilityAssignmentStatus::class),
            SelectFilter::make('assignment_mode')->options(ResponsibilityAssignmentMode::class),
            SelectFilter::make('employee_id')->relationship('employee', 'name')->searchable()->preload(),
        ])->recordActions([
            ViewAction::make()->label('View History'),
            Action::make('transfer')->requiresConfirmation()->authorize(fn (ResponsibilityAssignment $record): bool => auth()->user()->can('transfer', $record))
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active)
                ->schema([
                    Select::make('employee_id')->label('Transfer To')->required()->searchable()->options(fn (): array => Employee::query()->where('status', true)->whereNotNull('user_id')->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])->action(fn (ResponsibilityAssignment $record, array $data) => app(TransferResponsibilityAssignment::class)->handle($record, new TransferResponsibilityAssignmentData((int) $data['employee_id'], $data['reason'], (string) Str::uuid()), auth()->user())),
            Action::make('changeQuantity')->label('Change Quantity')->requiresConfirmation()->authorize(fn (ResponsibilityAssignment $record): bool => auth()->user()->can('changeQuantity', $record))
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active && $record->assignment_mode === ResponsibilityAssignmentMode::Quantity)
                ->schema([TextInput::make('quantity')->numeric()->integer()->minValue(1)->required(), Textarea::make('reason')->required()->maxLength(2000)])
                ->action(fn (ResponsibilityAssignment $record, array $data) => app(ChangeResponsibilityQuantity::class)->handle($record, new ChangeResponsibilityQuantityData((int) $data['quantity'], $data['reason'], (string) Str::uuid()), auth()->user())),
            Action::make('deactivate')->color('danger')->requiresConfirmation()->authorize(fn (ResponsibilityAssignment $record): bool => auth()->user()->can('deactivate', $record))
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active)
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->action(fn (ResponsibilityAssignment $record, array $data) => app(DeactivateResponsibilityAssignment::class)->handle($record, new DeactivateResponsibilityAssignmentData($data['reason']), auth()->user())),
        ])->toolbarActions([
            BulkAction::make('deactivateSelected')
                ->label('Deactivate Selected')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Deactivate selected Responsibility Assignments')
                ->modalDescription('All selected assignments will be validated together. If any assignment is blocked, none will be deactivated.')
                ->schema([
                    Textarea::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(2000),
                ])
                ->visible(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::Deactivate))
                ->authorize(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::Deactivate))
                ->deselectRecordsAfterCompletion()
                ->action(function (Collection $records, array $data): void {
                    $user = auth()->user();
                    abort_unless($user instanceof User, 403);

                    app(DeactivateResponsibilityAssignments::class)->handle(
                        $records,
                        new DeactivateResponsibilityAssignmentData($data['reason']),
                        $user,
                    );
                }),
        ])
            ->checkIfRecordIsSelectableUsing(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active
                && auth()->user()?->can('deactivate', $record) === true)
            ->emptyStateHeading('No Responsibility Assignments found for the selected filters.');
    }
}
