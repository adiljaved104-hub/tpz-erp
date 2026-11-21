<?php

namespace App\Filament\Resources\StockRequests\Schemas;

use App\Enums\StockRequestPurpose;
use App\Models\Order;
use App\Models\ProductInventory;
use App\Models\User;
use App\Services\Inventory\StockRequestService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class StockRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }


    /**
     * A compact, searchable request grid for the native Inventory Workspace.
     * Classic Stock Requests retains its original form and workflow.
     *
     * The inventory search, source preview and final submission all use
     * StockRequestService, preserving employee scope and approval rules.
     */
    public static function workspaceComponents(): array
    {
        $details = self::components()[0];

        return [
            $details,
            Section::make('Browse & Request Stock')
                ->description('Search SKU, product name, model or brand. Add several authorized products, then enter request quantities. Stock is not moved or reserved when submitting.')
                ->schema([
                    Select::make('quick_add_inventories')
                        ->label('Find & Add Products')
                        ->placeholder('Search SKU, product name, model or brand (2+ characters)')
                        ->multiple()
                        ->searchable()
                        ->live()
                        ->dehydrated(false)
                        ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)
                            ->searchInventories(auth()->user(), $search)
                            ->mapWithKeys(fn (ProductInventory $inventory): array => [
                                $inventory->id => app(StockRequestService::class)->inventoryLabel(auth()->user(), $inventory),
                            ])->all())
                        ->getOptionLabelsUsing(function (array $values): array {
                            $user = auth()->user();
                            if (! $user instanceof User) {
                                return [];
                            }

                            $service = app(StockRequestService::class);

                            return collect($values)->mapWithKeys(function ($value) use ($service, $user): array {
                                $inventory = $service->requestableInventory($user, (int) $value);

                                return $inventory === null ? [] : [
                                    $value => $service->inventoryLabel($user, $inventory),
                                ];
                            })->all();
                        })
                        ->afterStateUpdated(function (?array $state, Set $set, Get $get): void {
                            $selected = collect($get('items') ?? [])
                                ->filter(fn ($line): bool => is_array($line) && (int) ($line['product_inventory_id'] ?? 0) > 0)
                                ->all();
                            $existingIds = collect($selected)
                                ->pluck('product_inventory_id')->map(fn ($id): int => (int) $id)->all();
                            $user = auth()->user();
                            if (! $user instanceof User) {
                                return;
                            }

                            $service = app(StockRequestService::class);
                            foreach ($state ?? [] as $id) {
                                $inventoryId = (int) $id;
                                if ($inventoryId < 1 || in_array($inventoryId, $existingIds, true) || count($selected) >= 100) {
                                    continue;
                                }
                                // Re-check permission at selection time; final service
                                // validation independently checks stock responsibility.
                                if ($service->requestableInventory($user, $inventoryId) === null) {
                                    continue;
                                }
                                $selected[(string) Str::uuid()] = [
                                    'product_inventory_id' => $inventoryId,
                                    'quantity' => 1,
                                ];
                                $existingIds[] = $inventoryId;
                            }

                            $set('items', $selected);
                            $set('quick_add_inventories', []);
                        })
                        ->helperText('Select multiple matches to add them to the grid. Duplicate product/warehouse combinations are ignored.'),
                    Repeater::make('items')
                        ->label('Selected Products')
                        ->defaultItems(0)
                        ->minItems(1)
                        ->maxItems(100)
                        ->addActionLabel('Add Another Product')
                        ->table([
                            TableColumn::make('Product / SKU / Warehouse'),
                            TableColumn::make('Request Qty'),
                            TableColumn::make('Available Sources'),
                        ])
                        ->schema([
                            Select::make('product_inventory_id')
                                ->hiddenLabel()
                                ->required()
                                ->distinct()
                                ->searchable()
                                ->live()
                                ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)
                                    ->searchInventories(auth()->user(), $search)
                                    ->mapWithKeys(fn (ProductInventory $inventory): array => [
                                        $inventory->id => app(StockRequestService::class)->inventoryLabel(auth()->user(), $inventory),
                                    ])->all())
                                ->getOptionLabelUsing(function ($value): ?string {
                                    $user = auth()->user();
                                    $inventory = $user instanceof User
                                        ? app(StockRequestService::class)->requestableInventory($user, (int) $value)
                                        : null;

                                    return $inventory === null ? null : app(StockRequestService::class)->inventoryLabel($user, $inventory);
                                })
                                ->wrapOptionLabels(),
                            TextInput::make('quantity')
                                ->hiddenLabel()
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->required()
                                ->default(1),
                            Placeholder::make('source_availability')
                                ->hiddenLabel()
                                ->content(function (Get $get): string {
                                    $id = (int) $get('product_inventory_id');
                                    $user = auth()->user();
                                    if ($id < 1 || ! $user instanceof User) {
                                        return 'Select a product';
                                    }

                                    $availability = app(StockRequestService::class)->sourceAvailability($user, $id);
                                    $holders = $availability['holders']->map(
                                        fn (array $source): string => $source['label'].': '.$source['available_quantity'],
                                    )->join(' · ');

                                    return 'Total: '.$availability['requestable_available']
                                        .' · System: '.$availability['system_unassigned']
                                        .($holders === '' ? '' : ' · '.$holders);
                                }),
                        ]),
                ]),
        ];
    }

    public static function components(): array
    {
        return [
            Section::make('Request Details')->columns(2)->schema([
                Placeholder::make('requested_by')->label('Requested By')
                    ->content(function (): string {
                        $employee = auth()->user()?->employee;

                        return $employee === null
                            ? 'Active employee login required'
                            : trim("{$employee->employee_id} — {$employee->name}", ' —');
                    })->columnSpanFull(),
                Select::make('purpose')
                    ->options(collect(StockRequestPurpose::cases())->mapWithKeys(fn (StockRequestPurpose $purpose): array => [$purpose->value => $purpose->getLabel()])->all())
                    ->required()->live()->default(StockRequestPurpose::ForOrder->value),
                Select::make('order_id')->label('Order')
                    ->visible(fn (Get $get): bool => $get('purpose') === StockRequestPurpose::ForOrder->value)
                    ->required(fn (Get $get): bool => $get('purpose') === StockRequestPurpose::ForOrder->value)
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)->searchOrders(auth()->user(), $search)
                        ->mapWithKeys(fn (Order $order): array => [$order->id => trim("{$order->reference} · {$order->external_order_number}", ' ·')])->all())
                    ->getOptionLabelUsing(function ($value): ?string {
                        $user = auth()->user();
                        $order = $user instanceof User && (int) $value > 0
                            ? app(StockRequestService::class)->requestableOrder($user, (int) $value)
                            : null;

                        return $order === null ? null : trim("{$order->reference} · {$order->external_order_number}", ' ·');
                    }),
                Textarea::make('reason')->required()->minLength(5)->maxLength(2000)->rows(3)->columnSpanFull()
                    ->helperText('Explain why the stock is required. Creating this request does not move or reserve stock.'),
            ]),
            Section::make('Products')->description('Sources are calculated automatically. System / Unassigned stock is shown separately and requires Owner/Admin approval.')->schema([
                Repeater::make('items')->minItems(1)->maxItems(100)->defaultItems(1)->addActionLabel('Add Product')->columns(['default' => 1, 'md' => 12])->schema([
                    Select::make('product_inventory_id')->label('Product / Warehouse')->required()->distinct()->searchable()->live()
                        ->getSearchResultsUsing(fn (string $search): array => app(StockRequestService::class)->searchInventories(auth()->user(), $search)
                            ->mapWithKeys(fn (ProductInventory $inventory): array => [$inventory->id => app(StockRequestService::class)->inventoryLabel(auth()->user(), $inventory)])->all())
                        ->getOptionLabelUsing(function ($value): ?string {
                            $user = auth()->user();
                            if (! $user instanceof User) {
                                return null;
                            }

                            $inventory = app(StockRequestService::class)->requestableInventory($user, (int) $value);

                            return $inventory === null ? null : app(StockRequestService::class)->inventoryLabel($user, $inventory);
                        })->wrapOptionLabels()->columnSpan(['default' => 1, 'md' => 10]),
                    TextInput::make('quantity')->numeric()->integer()->minValue(1)->required()->default(1)->columnSpan(['default' => 1, 'md' => 2]),
                    Placeholder::make('source_availability')->label('Proposed Available Sources')->content(function (Get $get): string {
                        $inventoryId = (int) $get('product_inventory_id');
                        if ($inventoryId < 1) {
                            return 'Select a product to see Employee/Team source availability.';
                        }
                        $user = auth()->user();
                        if (! $user instanceof User) {
                            return 'An active employee login is required to view stock sources.';
                        }
                        $availability = app(StockRequestService::class)->sourceAvailability($user, $inventoryId);
                        $holders = $availability['holders']->map(fn (array $source): string => "{$source['label']}: {$source['available_quantity']}")->join(' · ');

                        return ($holders === '' ? 'Employee/Team sources: none' : $holders)." · System / Unassigned: {$availability['system_unassigned']} · Total requestable: {$availability['requestable_available']}";
                    })->columnSpanFull(),
                ]),
            ]),
        ];
    }
}
