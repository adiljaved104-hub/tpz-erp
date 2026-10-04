<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Tables;

use App\Actions\Responsibilities\ChangeResponsibilityQuantity;
use App\Actions\Responsibilities\ChangeResponsibilityScope;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignment;
use App\Actions\Responsibilities\DeactivateResponsibilityAssignments;
use App\Actions\Responsibilities\TransferResponsibilityAssignment;
use App\DTOs\Responsibilities\ChangeResponsibilityQuantityData;
use App\DTOs\Responsibilities\ChangeResponsibilityScopeData;
use App\DTOs\Responsibilities\DeactivateResponsibilityAssignmentData;
use App\DTOs\Responsibilities\TransferResponsibilityAssignmentData;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Exceptions\DuplicateActiveResponsibilityException;
use App\Exceptions\InvalidResponsibilityScopeException;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityAllocationService;
use App\Services\Responsibilities\ResponsibilityAssignmentService;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ResponsibilityAssignmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(),
            TextColumn::make('employee.name')->label('Employee')->searchable()->sortable()->description(fn (ResponsibilityAssignment $record): ?string => $record->employee?->employee_id),
            TextColumn::make('team_name_at_assignment')->label('Team')->placeholder('—'),
            TextColumn::make('assign_stock_by_default')->label('Default Stock')->formatStateUsing(fn (bool $state): string => $state ? 'Yes' : 'No')->badge(),
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
            Action::make('setStockDefault')->label('Set Default Stock')->requiresConfirmation()
                ->modalDescription('Controls future receipts only. A Brand, Category, or Product scope is required; warehouse visibility alone does not assign stock ownership.')
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active && $record->assignment_mode === ResponsibilityAssignmentMode::Scope)
                ->authorize(fn (): bool => ($user = auth()->user()) instanceof User
                    && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::Assign))
                ->schema([
                    Checkbox::make('enabled')->label('Assign stock by default')->default(fn (ResponsibilityAssignment $record): bool => $record->assign_stock_by_default),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])
                ->action(function (ResponsibilityAssignment $record, array $data, $livewire): void {
                    try {
                        app(ResponsibilityAssignmentService::class)->setStockDefault($record, (bool) $data['enabled'], $data['reason'], auth()->user());
                    } catch (ValidationException $exception) {
                        Notification::make()->danger()->title('Default stock assignment was not changed')
                            ->body(collect($exception->errors())->flatten()->join(' '))->send();
                        $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                        throw ValidationException::withMessages(collect($exception->errors())
                            ->mapWithKeys(fn (array $messages, string $key): array => ["{$path}.{$key}" => $messages])->all());
                    }
                    Notification::make()->success()->title('Default stock assignment updated')->send();
                }),
            Action::make('transfer')->label('Transfer Responsibility')->requiresConfirmation()
                ->modalDescription('Future receipts follow the new default holder. Existing allocated stock remains with its current holder until transferred through the stock request workflow.')
                ->authorize(fn (ResponsibilityAssignment $record): bool => auth()->user()->can('transfer', $record))
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active && $record->assignment_mode === ResponsibilityAssignmentMode::Quantity)
                ->schema([
                    Select::make('employee_id')->label('Transfer To')->required()->searchable()->options(fn (): array => Employee::query()->where('status', true)->whereNotNull('user_id')->orderBy('name')->pluck('name', 'id')->all()),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])->action(fn (ResponsibilityAssignment $record, array $data) => app(TransferResponsibilityAssignment::class)->handle($record, new TransferResponsibilityAssignmentData((int) $data['employee_id'], $data['reason'], (string) Str::uuid()), auth()->user())),
            Action::make('changeScope')->label('Change / Transfer Responsibility')->icon('heroicon-o-arrow-path')->requiresConfirmation()
                ->modalDescription('This creates a new historical version. Existing inventory ownership, reservations, and physical quantities are not moved.')
                ->authorize(fn (ResponsibilityAssignment $record): bool => auth()->user()->can('transfer', $record))
                ->visible(fn (ResponsibilityAssignment $record): bool => $record->status === ResponsibilityAssignmentStatus::Active && $record->assignment_mode === ResponsibilityAssignmentMode::Scope)
                ->schema(fn (ResponsibilityAssignment $record): array => [
                    Placeholder::make('current_scope')->label('Current Responsibility')->content(fn (): string => self::describeScope($record)),
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()->default($record->employee_id)
                        ->options(fn (): array => Employee::query()->where('status', true)->whereNotNull('user_id')->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('brand_id')->label('Brand')->searchable()->nullable()->live()->default($record->brandScope?->product_brand_id)
                        ->options(fn (): array => ProductBrand::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('product_id')->label('Product')->searchable()->nullable()->live()->default($record->productScope?->product_id)
                        ->options(fn (): array => Product::query()->products()->where('status', ProductStatus::Active->value)->orderBy('name')->get(['id', 'sku', 'name'])->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} — {$product->name}"])->all()),
                    Select::make('category_id')->label('Category')->searchable()->nullable()->live()->default($record->categoryScope?->product_category_id)
                        ->options(fn (): array => ProductCategory::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('condition')->label('Condition')->searchable()->nullable()->live()->default($record->conditionScope?->product_condition?->value)
                        ->options(collect(ProductCondition::cases())->mapWithKeys(fn (ProductCondition $condition): array => [$condition->value => $condition->label()])->all()),
                    Select::make('warehouse_id')->label('Warehouse')->searchable()->nullable()->live()->default($record->warehouseScope?->warehouse_id)
                        ->options(fn (): array => Warehouse::query()->where('status', true)->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('platform_id')->label('Platform')->searchable()->nullable()->live()->default($record->platformScope?->marketplace_platform_id)
                        ->options(fn (): array => MarketplacePlatform::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                    Checkbox::make('assign_stock_by_default')->label('Assign stock by default')->live()->default($record->assign_stock_by_default)
                        ->helperText('Controls future receipt ownership only. Existing Allocation Balances are not changed.'),
                    Placeholder::make('scope')->label('Preflight Summary')->content(function (Get $get) use ($record): string {
                        return app(ResponsibilityAssignmentService::class)->previewScopeChange($record, self::scopeChangeData([
                            'employee_id' => $get('employee_id'), 'brand_id' => $get('brand_id'), 'product_id' => $get('product_id'),
                            'category_id' => $get('category_id'), 'condition' => $get('condition'), 'warehouse_id' => $get('warehouse_id'),
                            'platform_id' => $get('platform_id'), 'assign_stock_by_default' => $get('assign_stock_by_default'),
                        ], 'Preflight only', 'scope-preview'));
                    }),
                    Textarea::make('reason')->required()->maxLength(2000),
                ])
                ->action(function (ResponsibilityAssignment $record, array $data, $livewire): void {
                    try {
                        app(ChangeResponsibilityScope::class)->handle($record, self::scopeChangeData($data), auth()->user());
                    } catch (ValidationException|InvalidResponsibilityScopeException|DuplicateActiveResponsibilityException $exception) {
                        $errors = $exception instanceof ValidationException
                            ? $exception->errors()
                            : ['scope' => [$exception->getMessage()]];
                        Notification::make()->danger()->title('Responsibility was not changed')
                            ->body(collect($errors)->flatten()->join(' '))->send();
                        $path = $livewire->getSchema($livewire->getMountedActionSchemaName())->getStatePath();
                        throw ValidationException::withMessages(collect($errors)
                            ->mapWithKeys(fn (array $messages, string $key): array => ["{$path}.{$key}" => $messages])->all());
                    }
                    Notification::make()->success()->title('Responsibility changed')->body('The previous assignment is preserved in history. Existing stock ownership was not moved.')->send();
                }),
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

    private static function scopeChangeData(array $data, ?string $reason = null, ?string $idempotencyKey = null): ChangeResponsibilityScopeData
    {
        return new ChangeResponsibilityScopeData(
            employeeId: (int) ($data['employee_id'] ?? 0),
            brandId: self::nullableId($data['brand_id'] ?? null),
            productId: self::nullableId($data['product_id'] ?? null),
            categoryId: self::nullableId($data['category_id'] ?? null),
            condition: self::condition($data['condition'] ?? null),
            warehouseId: self::nullableId($data['warehouse_id'] ?? null),
            platformId: self::nullableId($data['platform_id'] ?? null),
            assignStockByDefault: (bool) ($data['assign_stock_by_default'] ?? false),
            reason: $reason ?? (string) ($data['reason'] ?? ''),
            idempotencyKey: $idempotencyKey ?? (string) Str::uuid(),
        );
    }

    private static function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private static function condition(mixed $value): ?ProductCondition
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ProductCondition::tryFrom((string) $value)
            ?? throw ValidationException::withMessages(['condition' => 'Select a valid Product Condition.']);
    }

    private static function describeScope(ResponsibilityAssignment $record): string
    {
        return collect([
            $record->employee?->name,
            $record->brandScope?->brand?->name ? 'Brand '.$record->brandScope->brand->name : null,
            $record->productScope?->product?->sku,
            $record->categoryScope?->category?->name ? 'Category '.$record->categoryScope->category->name : null,
            $record->conditionScope?->product_condition?->label() ? 'Condition '.$record->conditionScope->product_condition->label() : null,
            $record->warehouseScope?->warehouse?->name ? 'Warehouse '.$record->warehouseScope->warehouse->name : null,
            $record->platformScope?->platform?->name ? 'Platform '.$record->platformScope->platform->name : null,
            'Default stock: '.($record->assign_stock_by_default ? 'Yes' : 'No'),
        ])->filter()->implode(' · ');
    }
}
