<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Schemas;

use App\Enums\ProductStatus;
use App\Enums\ResponsibilityAssignmentMode;
use App\Models\Employee;
use App\Models\MarketplacePlatform;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ResponsibilityAssignment;
use App\Services\Responsibilities\ResponsibilityCapacityService;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ResponsibilityAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Responsibility')->schema([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Select::make('employee_id')->label('Employee')->required()->searchable()->live()
                        ->options(fn (): array => Employee::query()->where('status', true)->whereNotNull('user_id')->orderBy('name')->get()->mapWithKeys(fn (Employee $employee): array => [$employee->id => "{$employee->employee_id} · {$employee->name}"])->all()),
                    Placeholder::make('employee_context')->label('Employee Context')->content(function (Get $get): string {
                        $employee = Employee::query()->with('team')->find((int) $get('employee_id'));
                        if ($employee === null) {
                            return 'Select an Employee.';
                        }
                        $overlaps = ResponsibilityAssignment::query()->active()->where('employee_id', $employee->id)->count();

                        return 'Team: '.($employee->team?->name ?? 'No Team')."; Active assignments: {$overlaps}.";
                    }),
                    Select::make('scope_type')->label('Responsibility Type')->options([
                        'brand' => 'Brand', 'platform' => 'Platform', 'brand_platform' => 'Brand + Platform',
                        'product' => 'Product', 'product_platform' => 'Product + Platform',
                        'quantity' => 'Product Inventory + Quantity', 'quantity_platform' => 'Product Inventory + Quantity + Platform',
                    ])->required()->default('brand')->live()
                        ->afterStateUpdated(function (mixed $state, Set $set): void {
                            $set('assignment_mode', str_starts_with((string) $state, 'quantity') ? ResponsibilityAssignmentMode::Quantity->value : ResponsibilityAssignmentMode::Scope->value);
                            if (! in_array($state, ['brand', 'brand_platform'], true)) {
                                $set('brand_id', null);
                            }
                            if (! in_array($state, ['platform', 'brand_platform', 'product_platform', 'quantity_platform'], true)) {
                                $set('platform_id', null);
                            }
                            if (! in_array($state, ['product', 'product_platform'], true)) {
                                $set('product_id', null);
                            }
                            if (! in_array($state, ['quantity', 'quantity_platform'], true)) {
                                $set('product_inventory_id', null);
                                $set('assigned_quantity', null);
                            }
                        }),
                    Hidden::make('assignment_mode')->default(ResponsibilityAssignmentMode::Scope->value),
                    DateTimePicker::make('effective_at')->required()->default(now())->maxDate(now()),
                ]),
            ]),
            Section::make('Exact Scope')->description('One assignment represents one exact Brand, Platform, Product, or Product Inventory quantity combination.')->schema([
                Grid::make(['default' => 1, 'lg' => 2])->schema([
                    Select::make('brand_id')->label('Brand')->searchable()->live()->nullable()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['brand', 'brand_platform'], true))
                        ->required(fn (Get $get): bool => in_array($get('scope_type'), ['brand', 'brand_platform'], true))
                        ->options(fn (): array => ProductBrand::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('platform_id')->label('Platform')->searchable()->live()->nullable()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['platform', 'brand_platform', 'product_platform', 'quantity_platform'], true))
                        ->required(fn (Get $get): bool => in_array($get('scope_type'), ['platform', 'brand_platform', 'product_platform', 'quantity_platform'], true))
                        ->options(fn (): array => MarketplacePlatform::query()->active()->orderBy('name')->pluck('name', 'id')->all()),
                    Select::make('product_id')->label('Product')->searchable()->live()->nullable()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['product', 'product_platform'], true))
                        ->required(fn (Get $get): bool => in_array($get('scope_type'), ['product', 'product_platform'], true))
                        ->options(function (Get $get): array {
                            return Product::query()->products()->where('status', ProductStatus::Active->value)
                                ->when($get('brand_id'), fn ($query, $brandId) => $query->where('brand_id', $brandId))
                                ->orderBy('name')->get(['id', 'sku', 'name'])->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} — {$product->name}"])->all();
                        }),
                    Select::make('product_inventory_id')->label('Product / Warehouse')->searchable()->live()->nullable()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['quantity', 'quantity_platform'], true))
                        ->required(fn (Get $get): bool => in_array($get('scope_type'), ['quantity', 'quantity_platform'], true))
                        ->options(fn (): array => DB::table('product_inventories as pi')->join('products as p', 'p.id', '=', 'pi.product_id')->join('warehouses as w', 'w.id', '=', 'pi.warehouse_id')->where('p.inventory_item_type', 'product')->where('p.status', ProductStatus::Active->value)->where('w.status', true)->orderBy('p.name')->get(['pi.id', 'p.sku', 'p.name', 'w.name as warehouse'])->mapWithKeys(fn (object $row): array => [$row->id => "{$row->sku} — {$row->name} ({$row->warehouse})"])->all()),
                    TextInput::make('assigned_quantity')->label('Assigned Qty')->numeric()->integer()->minValue(1)->live()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['quantity', 'quantity_platform'], true))
                        ->required(fn (Get $get): bool => in_array($get('scope_type'), ['quantity', 'quantity_platform'], true)),
                    Placeholder::make('quantity_context')->label('Quantity Context')->columnSpanFull()
                        ->visible(fn (Get $get): bool => in_array($get('scope_type'), ['quantity', 'quantity_platform'], true))
                        ->content(function (Get $get): string {
                            $inventoryId = (int) $get('product_inventory_id');
                            if ($inventoryId < 1) {
                                return 'Select a Product / Warehouse.';
                            }
                            $summary = app(ResponsibilityCapacityService::class)->summary($inventoryId);
                            $inventory = $summary['inventory'];
                            $proposed = (int) ($get('assigned_quantity') ?? 0);

                            return "Available {$inventory->available_quantity}; Reserved {$inventory->reserved_quantity}; Sellable {$summary['sellable']}; Already Assigned {$summary['assigned']}; Remaining {$summary['remaining']}; Proposed {$proposed}; State {$summary['status']->getLabel()}.";
                        }),
                ]),
            ]),
            Section::make('Reason and Notes')->schema([
                Textarea::make('reason')->required()->maxLength(2000),
                Textarea::make('notes')->maxLength(5000),
                Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid()),
            ])->columns(1),
        ]);
    }
}
