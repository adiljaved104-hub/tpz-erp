<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\DamagedStockPermission;
use App\Enums\DamagedStockSource;
use App\Enums\DamagedStockStatus;
use App\Enums\InventoryPermission;
use App\Enums\WarrantyRepairPermission;
use App\Exceptions\WarrantyRepairException;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Models\DamagedStockEvent;
use App\Models\ProductInventory;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\DamagedStockAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Inventory\DamagedItemsQueueService;
use App\Services\Inventory\DamagedStockAvailabilityService;
use App\Services\ServiceCases\WarrantyRepairService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class DamagedItems extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory.damaged-items';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Damaged Items';

    protected static ?string $title = 'Damaged Items';

    #[Url]
    public string $search = '';

    #[Url]
    public ?int $warehouseId = null;

    #[Url]
    public string $source = '';

    #[Url]
    public ?int $platformId = null;

    #[Url]
    public string $status = 'damaged';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(DamagedStockAuthorization::class)->allows($user, DamagedStockPermission::View);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'warehouseId', 'source', 'platformId');
        $this->status = 'damaged';
        $this->resetPage();
    }

    public function canSendToRepair(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(DamagedStockAuthorization::class)->allows($user, DamagedStockPermission::View)
            && app(WarrantyRepairAuthorization::class)->allows($user, WarrantyRepairPermission::Create);
    }

    public function sendToRepairAction(): Action
    {
        return Action::make('sendToRepair')
            ->label('Send to Repair')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->modalHeading('Send damaged item to repair')
            ->modalSubmitActionLabel('Create repair case')
            ->fillForm(function (array $arguments): array {
                $damage = DamagedStockEvent::query()->findOrFail((int) $arguments['damageId']);
                $this->authorizeRepairContext($damage->product_id, $damage->warehouse_id, $damage->marketplace_platform_id);

                return [
                    'issue_description' => $damage->reason,
                    'quantity' => app(DamagedStockAvailabilityService::class)->availableForRepair($damage),
                ];
            })
            ->schema([
                TextInput::make('quantity')->label('Repair Qty')->integer()->minValue(1)->required()
                    ->helperText('Cannot exceed the remaining damaged quantity not already covered by an active repair.'),
                Textarea::make('issue_description')->label('Repair Issue / Instructions')->required()->maxLength(5000),
                TextInput::make('service_provider')->label('Technician / Service Provider')->maxLength(255),
                DateTimePicker::make('expected_return_at')->label('Expected Return')->minDate(now()),
                Textarea::make('notes')->maxLength(5000),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    $damage = DamagedStockEvent::query()->findOrFail((int) $arguments['damageId']);
                    $case = app(WarrantyRepairService::class)->createFromDamagedItem($damage, $data + ['idempotency_key' => (string) Str::uuid()], auth()->user());
                    Notification::make()->success()->title('Repair case created')->body("{$case->reference} is ready in Internal Repairs.")->actions([
                        Action::make('view')->label('Open internal repair')->url(InternalRepairResource::getUrl('view', ['record' => $case])),
                    ])->send();
                    $this->redirect(InternalRepairResource::getUrl('view', ['record' => $case]), navigate: true);
                } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
                    Notification::make()->danger()->title('Cannot send item to repair')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to send this item to repair.'))->send();
                    throw new Halt;
                }
            });
    }

    public function startLegacyRepairAction(): Action
    {
        return Action::make('startLegacyRepair')
            ->label('Start Repair')
            ->icon(Heroicon::OutlinedWrenchScrewdriver)
            ->modalHeading('Start repair for Old Damaged Stock')
            ->modalDescription('This records a new Internal Repair without inventing historical damage provenance.')
            ->modalSubmitActionLabel('Create internal repair')
            ->fillForm(function (array $arguments): array {
                $inventory = ProductInventory::query()->findOrFail((int) $arguments['inventoryId']);
                app(InventoryAuthorization::class)->authorize(auth()->user(), InventoryPermission::View, $inventory);
                $this->authorizeRepairContext($inventory->product_id, $inventory->warehouse_id, null);

                return ['quantity' => app(DamagedStockAvailabilityService::class)->legacyAvailableForRepair($inventory)];
            })
            ->schema([
                TextInput::make('quantity')->label('Repair Qty')->integer()->minValue(1)->required()
                    ->helperText('Cannot exceed the currently unallocated Old Damaged Stock quantity.'),
                Textarea::make('issue_description')->label('Repair Issue / Instructions')->maxLength(5000),
                TextInput::make('service_provider')->label('Technician / Service Provider')->maxLength(255),
                DateTimePicker::make('expected_return_at')->label('Expected Return')->minDate(now()),
                Textarea::make('notes')->maxLength(5000),
            ])
            ->action(function (array $data, array $arguments): void {
                try {
                    $inventory = ProductInventory::query()->findOrFail((int) $arguments['inventoryId']);
                    $case = app(WarrantyRepairService::class)->createFromLegacyDamagedInventory($inventory, $data + ['idempotency_key' => (string) Str::uuid()], auth()->user());
                    Notification::make()->success()->title("Internal Repair {$case->reference} created")->body('The Old Damaged Stock remains in Damaged inventory until QC Pass.')->send();
                    $this->redirect(InternalRepairResource::getUrl('view', ['record' => $case]), navigate: true);
                } catch (WarrantyRepairException|ValidationException|AuthorizationException $exception) {
                    Notification::make()->danger()->title('Cannot start Internal Repair')->body($exception instanceof ValidationException ? collect($exception->errors())->flatten()->first() : ($exception->getMessage() ?: 'You are not authorized to start this repair.'))->send();
                    throw new Halt;
                }
            });
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        /** @var User $user */
        $user = auth()->user();
        $service = app(DamagedItemsQueueService::class);
        $filters = $this->filters();
        $options = $service->filterOptions($user);

        return [
            'rows' => $service->paginate($user, $filters),
            'summary' => $service->summary($user, $filters),
            'warehouses' => $options['warehouses'],
            'platforms' => $options['platforms'],
            'sources' => collect(DamagedStockSource::cases())->mapWithKeys(fn (DamagedStockSource $source): array => [$source->value => $source->getLabel()])->prepend('Old Damaged Stock', 'legacy'),
            'statuses' => collect(DamagedStockStatus::cases())->mapWithKeys(fn (DamagedStockStatus $status): array => [$status->value => $status->getLabel()]),
            'canSendToRepair' => $this->canSendToRepair(),
        ];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'search' => $this->search,
            'warehouse_id' => $this->warehouseId,
            'source' => $this->source ?: null,
            'platform_id' => $this->platformId,
            'status' => $this->status ?: null,
        ];
    }

    private function authorizeRepairContext(int $productId, int $warehouseId, ?int $platformId): void
    {
        app(WarrantyRepairAuthorization::class)->authorize(auth()->user(), WarrantyRepairPermission::Create, new WarrantyRepair([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'marketplace_platform_id' => $platformId,
        ]));
    }
}
