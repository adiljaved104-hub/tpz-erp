<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Tables;

use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Models\Employee;
use App\Models\ResponsibilityAssignment;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
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
            TextColumn::make('platformScope.platform.name')->label('Platform')->placeholder('—'),
            TextColumn::make('product_display')->label('Product')->state(fn (ResponsibilityAssignment $record): ?string => $record->productScope?->product?->name ?? $record->quantityScope?->inventory?->product?->name)->placeholder('—')->wrap(),
            TextColumn::make('warehouse_display')->label('Warehouse')->state(fn (ResponsibilityAssignment $record): ?string => $record->quantityScope?->inventory?->warehouse?->name)->placeholder('—'),
            TextColumn::make('quantityScope.assigned_quantity')->label('Assigned Qty')->placeholder('—'),
            TextColumn::make('physical_sellable')->label('Physical Sellable')->placeholder('—'),
            TextColumn::make('aggregate_assigned')->label('Aggregate Assigned')->placeholder('—'),
            TextColumn::make('remaining_assignable')->label('Remaining')->state(fn (ResponsibilityAssignment $record): ?int => $record->physical_sellable === null ? null : (int) $record->physical_sellable - (int) $record->aggregate_assigned)->placeholder('—'),
            TextColumn::make('capacity_state')->label('Capacity')->state(fn (ResponsibilityAssignment $record): string => $record->physical_sellable === null ? 'Not Applicable' : ((int) $record->aggregate_assigned > (int) $record->physical_sellable ? 'Over Assigned' : ((int) $record->aggregate_assigned === (int) $record->physical_sellable ? 'At Capacity' : 'OK')))->badge()
                ->color(fn (string $state): string => match ($state) {
                    'Over Assigned' => 'danger', 'At Capacity' => 'warning', 'OK' => 'success', default => 'gray'
                }),
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
        ])->toolbarActions([])
            ->emptyStateHeading('No Responsibility Assignments found for the selected filters.');
    }
}
