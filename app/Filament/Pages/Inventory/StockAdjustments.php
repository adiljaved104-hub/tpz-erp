<?php

namespace App\Filament\Pages\Inventory;

use App\DTOs\Inventory\InventoryAdjustmentData;
use App\Enums\InventoryPermission;
use App\Enums\PurchasePermission;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Models\InventoryAdjustment;
use App\Models\InventoryAllocationAccount;
use App\Models\ProductInventory;
use App\Models\PurchaseReceiptItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Inventory\InventoryAdjustmentService;
use App\Services\Inventory\InventoryAllocationService;
use App\Services\Inventory\InventoryReadService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustments extends Page
{
    protected static ?string $slug = 'stock-adjustments';

    protected static ?string $navigationLabel = 'Stock Adjustments';

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?int $navigationSort = 5;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(InventoryAuthorization::class)->allows($user, InventoryPermission::AdjustStock);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $receiptItemId = (int) request()->query('receipt_item_id', 0);
        $line = ['idempotency_key' => (string) Str::uuid(), 'quantity' => 1];
        if ($receiptItemId > 0) {
            $item = PurchaseReceiptItem::query()->with('receipt.purchase')->findOrFail($receiptItemId);
            app(PurchaseAuthorization::class)->authorize(auth()->user(), PurchasePermission::ViewReceipts, $item->receipt->purchase);
            $inventory = ProductInventory::query()->where('product_id', $item->product_id)
                ->where('warehouse_id', $item->receipt->warehouse_id)->firstOrFail();
            app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::AdjustStock, $inventory);
            $line += ['inventory_id' => $inventory->id, 'receipt_item_id' => $item->id];
        }
        $this->getSchema('content')->fill(['lines' => [$line]]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Prepare Stock Adjustments')
                ->description('Changes stay here until Save All. Reserved quantity is read-only. A new delivery must use Quick Stock Purchase.')
                ->schema([
                    Repeater::make('lines')->label('Adjustment Lines')->minItems(1)->defaultItems(1)
                        ->addActionLabel('Add Product')->reorderable(false)->schema([
                            Select::make('inventory_id')->label('Product / Warehouse')->required()->searchable()->live()
                                ->options(fn (): array => app(InventoryReadService::class)->inventories(auth()->user())
                                    ->get()->mapWithKeys(fn (ProductInventory $inventory): array => [
                                        $inventory->id => $inventory->product->sku.' — '.$inventory->product->name.' / '.$inventory->warehouse->name,
                                    ])->all()),
                            Placeholder::make('current_balances')->label('Current stock')
                                ->content(function (Get $get): string {
                                    $inventory = ProductInventory::query()->find($get('inventory_id'));
                                    if ($inventory === null || ! app(InventoryAuthorization::class)->allows(auth()->user(), InventoryPermission::AdjustStock, $inventory)) {
                                        return 'Select an authorized Product / Warehouse.';
                                    }

                                    return "Saleable {$inventory->available_quantity}; Reserved {$inventory->reserved_quantity}; Damaged {$inventory->damaged_quantity}";
                                }),
                            Select::make('type')->label('Adjustment type')->required()->live()->options([
                                'saleable_increase' => 'Saleable +',
                                'saleable_decrease' => 'Saleable −',
                                'mark_damaged' => 'Saleable − / Damaged +',
                                'restore_damaged' => 'Damaged − / Saleable +',
                            ]),
                            TextInput::make('quantity')->label('Quantity')->integer()->numeric()->minValue(1)->required(),
                            Radio::make('is_new_purchase')->label('Is this additional stock from a new purchase or delivery?')
                                ->options(['yes' => 'Yes — New / additional stock', 'no' => 'No — Stock correction / discrepancy'])
                                ->required()->visible(fn (Get $get): bool => $get('type') === 'saleable_increase'),
                            Select::make('allocation_account_id')->label('Allocation holder for standalone increase')->searchable()
                                ->options(fn (): array => InventoryAllocationAccount::query()->where('status', true)->orderBy('name')
                                    ->get()->filter(fn (InventoryAllocationAccount $account): bool => app(InventoryAllocationService::class)
                                    ->canConsumeFromAccount(auth()->user(), $account))
                                    ->pluck('name', 'id')->all())
                                ->visible(fn (Get $get): bool => $get('type') === 'saleable_increase' && ! $get('receipt_item_id')),
                            TextInput::make('valuation_unit_cost')->label('Valuation cost if stock balance is empty (AED)')
                                ->rule('regex:/^\d{1,11}(?:\.\d{1,4})?$/')
                                ->visible(fn (Get $get): bool => $get('type') === 'saleable_increase' && ! $get('receipt_item_id')),
                            Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(2000),
                            Hidden::make('receipt_item_id'),
                            Hidden::make('idempotency_key')->default(fn (): string => (string) Str::uuid())->required()->uuid(),
                        ])->columns(2),
                    Actions::make([Action::make('saveAll')->label('Save All')->action(fn () => $this->saveAll())])->alignEnd(),
                ]),
            Section::make('Adjustment History')->schema([
                TextInput::make('history_search')->label('Search reference, SKU, or reason')->live(onBlur: true),
                Select::make('history_type')->label('Type')->options([
                    'saleable_increase' => 'Saleable +', 'saleable_decrease' => 'Saleable −',
                    'mark_damaged' => 'Moved to Damaged', 'restore_damaged' => 'Restored to Saleable',
                ])->live(),
                Select::make('history_employee_id')->label('Employee')->searchable()
                    ->options(fn (): array => User::query()->whereHas('employee', fn ($query) => $query->where('status', true))
                        ->orderBy('name')->pluck('name', 'id')->all())->live(),
                Select::make('history_warehouse_id')->label('Warehouse')
                    ->options(fn (): array => Warehouse::query()->orderBy('name')->pluck('name', 'id')->all())->live(),
                DatePicker::make('history_date')->label('Date')->live(),
                Placeholder::make('history')->label('Recent adjustments')->content(fn (): HtmlString => $this->history()),
            ]),
        ]);
    }

    public function saveAll(): void
    {
        abort_unless(static::canAccess(), 403);
        $state = $this->getSchema('content')->getState();
        $lines = array_values($state['lines'] ?? []);
        foreach ($lines as $line) {
            if (($line['type'] ?? null) === 'saleable_increase' && ($line['is_new_purchase'] ?? null) === 'yes') {
                if (! QuickStockPurchase::canAccess()) {
                    throw ValidationException::withMessages(['lines' => 'You need Quick Stock Purchase permission to receive a new delivery.']);
                }
                $inventory = ProductInventory::query()->findOrFail((int) $line['inventory_id']);
                app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::AdjustStock, $inventory);
                Notification::make()->info()->title('Continue in Quick Stock Purchase')->body('No stock adjustment was posted.')->send();
                $this->redirect(QuickStockPurchase::getUrl(['product_id' => $inventory->product_id, 'warehouse_id' => $inventory->warehouse_id]), navigate: true);

                return;
            }
        }

        $data = array_map(fn (array $line): InventoryAdjustmentData => new InventoryAdjustmentData(
            inventoryId: (int) $line['inventory_id'],
            type: (string) $line['type'],
            quantity: (int) $line['quantity'],
            reason: (string) $line['reason'],
            idempotencyKey: (string) $line['idempotency_key'],
            receiptItemId: filled($line['receipt_item_id'] ?? null) ? (int) $line['receipt_item_id'] : null,
            allocationAccountId: filled($line['allocation_account_id'] ?? null) ? (int) $line['allocation_account_id'] : null,
            isNewPurchase: ($line['type'] ?? null) === 'saleable_increase' ? false : null,
            valuationUnitCost: filled($line['valuation_unit_cost'] ?? null) ? (string) $line['valuation_unit_cost'] : null,
        ), $lines);
        try {
            $posted = app(InventoryAdjustmentService::class)->postBatch($data, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Stock adjustments were not posted')
                ->body(collect($exception->errors())->flatten()->first())->send();
            throw $exception;
        }
        Notification::make()->success()->title(count($posted).' stock adjustment(s) posted')
            ->body(collect($posted)->pluck('reference')->implode(', '))->send();
        $this->getSchema('content')->fill(['lines' => [['idempotency_key' => (string) Str::uuid(), 'quantity' => 1]]]);
    }

    private function history(): HtmlString
    {
        $user = auth()->user();
        $inventoryIds = app(InventoryReadService::class)->inventories($user)->pluck('id');
        $query = InventoryAdjustment::query()->with(['product:id,sku,name', 'warehouse:id,name', 'performedBy:id,name'])
            ->whereIn('product_inventory_id', $inventoryIds)->orderByDesc('performed_at')->orderByDesc('id');
        $search = trim((string) ($this->data['history_search'] ?? ''));
        if ($search !== '') {
            $query->where(fn ($q) => $q->where('reference', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('product', fn ($product) => $product->where('sku', 'like', "%{$search}%")));
        }
        if (filled($this->data['history_type'] ?? null)) {
            $query->where('type', $this->data['history_type']);
        }
        if (filled($this->data['history_employee_id'] ?? null)) {
            $query->where('performed_by_user_id', (int) $this->data['history_employee_id']);
        }
        if (filled($this->data['history_warehouse_id'] ?? null)) {
            $query->where('warehouse_id', (int) $this->data['history_warehouse_id']);
        }
        if (filled($this->data['history_date'] ?? null)) {
            $query->whereDate('performed_at', $this->data['history_date']);
        }
        $rows = $query->limit(25)->get();
        if ($rows->isEmpty()) {
            return new HtmlString('No adjustments match these filters.');
        }
        $html = '<div style="overflow-x:auto"><table class="w-full text-sm"><thead><tr><th>Reference</th><th>Product</th><th>Warehouse</th><th>Saleable</th><th>Damaged</th><th>Type</th><th>By</th><th>When</th><th>GRN</th><th>Reason</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $values = [$row->reference, $row->product?->sku, $row->warehouse?->name,
                sprintf('%+d', $row->available_delta), sprintf('%+d', $row->damaged_delta),
                str_replace('_', ' ', $row->type), $row->performedBy?->name,
                $row->performed_at?->format('d M Y H:i'), $row->grn_reference, $row->reason];
            $html .= '<tr>'.collect($values)->map(fn ($value): string => '<td class="p-2 border-b">'.e((string) $value).'</td>')->implode('').'</tr>';
        }

        return new HtmlString($html.'</tbody></table></div>');
    }
}
