<?php

namespace App\Filament\Pages;

use App\DTOs\StockRequests\CreateStockRequestData;
use App\DTOs\StockRequests\StockRequestItemData;
use App\Enums\InventoryPermission;
use App\Enums\InventoryReservationStatus;
use App\Enums\ProductPermission;
use App\Enums\StockRequestPurpose;
use App\Enums\StockRequestSourceStatus;
use App\Filament\Pages\Inventory\InventoryOverview;
use App\Filament\Pages\Inventory\StockAdjustments;
use App\Filament\Pages\Inventory\StockByHolder;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\StockRequests\Schemas\StockRequestForm;
use App\Filament\Resources\StockRequests\StockRequestResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Models\StockRequest;
use App\Models\InventoryReservation;
use App\Models\StockRequestExecution;
use App\Models\StockRequestSourceLine;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Dashboard\ErpDashboardService;
use App\Services\Inventory\EmployeeOwnedInventoryReadService;
use App\Services\Inventory\InventoryLocationOverviewService;
use App\Services\Inventory\InventoryReadService;
use App\Services\Inventory\StockRequestApprovalService;
use App\Services\Inventory\StockRequestExecutionService;
use App\Services\Inventory\StockRequestService;
use App\Services\Inventory\StockByHolderReportService;
use App\Services\Mobile\StockStatus;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Navigation\WorkspaceRecordPreviewService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;

class InventoryWorkspace extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.inventory-workspace';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory & Warehouse';

    protected static ?string $navigationLabel = 'Inventory';

    protected static ?string $slug = 'inventory-workspace';

    protected static ?int $navigationSort = 0;

    #[Url(as: 'tab', history: true, except: 'overview')]
    public string $activeTab = 'overview';

    #[Url(as: 'module', history: true)]
    public ?int $activeExtraModule = null;

    public string $search = '';

    // This is a view filter only. Never mutate stock or allocation balances.
    public string $inventoryStockFilter = 'in_stock';

    public string $productSearch = '';

    public string $myInventorySearch = '';

    public string $ownershipHolderId = '';
    public string $ownershipSearch = '';
    public string $ownershipBrandId = '';
    public string $ownershipCategoryId = '';
    public string $ownershipWarehouseId = '';
    public bool $ownershipUnassignedOnly = false;

    // Store only the selected inventory ID; always load through the scoped
    // InventoryReadService instead of persisting record data in Livewire state.
    public ?int $quickViewInventoryId = null;

    public ?int $pendingExecutionRequestId = null;

    public ?int $pendingOwnerTransferRequestId = null;

    /** @var array<int|string, int|string> */
    public array $ownerTransferQuantities = [];

    public ?int $pendingSourceDecisionRequestId = null;

    public ?int $pendingSourceDecisionLineId = null;

    public string $sourceDecision = 'approved';

    public int $sourceApprovedQuantity = 1;

    public string $sourceDecisionNote = '';

    #[Locked]
    public ?string $stockRequestSubmissionKey = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createStockRequest')
                ->label('Create Stock Request')
                ->modalHeading('Request Stock')
                ->modalDescription('Choose authorized products, enter quantities and submit for approval. No stock moves until approved and executed.')
                ->modalWidth('6xl')
                ->modalSubmitActionLabel('Submit Stock Request')
                ->visible(fn (): bool => static::canAccess() && StockRequestResource::canCreate())
                ->schema(StockRequestForm::workspaceComponents())
                ->mountUsing(function (Schema $schema): void {
                    abort_unless(static::canAccess() && StockRequestResource::canCreate(), 403);
                    $this->stockRequestSubmissionKey = (string) Str::uuid();
                    $schema->fill();
                })
                ->action(function (array $data): void {
                    abort_unless(static::canAccess() && StockRequestResource::canCreate() && $this->stockRequestSubmissionKey !== null, 403);
                    $actor = auth()->user();
                    $purpose = StockRequestPurpose::from($data['purpose']);
                    $orderId = $purpose === StockRequestPurpose::ForOrder ? (int) ($data['order_id'] ?? 0) : null;
                    $service = app(StockRequestService::class);
                    if ($orderId !== null && $service->requestableOrder($actor, $orderId) === null) {
                        throw ValidationException::withMessages(['order_id' => 'Select an Order in your authorized scope.']);
                    }

                    $request = $service->create(new CreateStockRequestData(
                        $purpose,
                        $orderId,
                        array_map(
                            fn (array $item): StockRequestItemData => new StockRequestItemData((int) $item['product_inventory_id'], (int) $item['quantity']),
                            array_values($data['items']),
                        ),
                        $data['reason'],
                        $this->stockRequestSubmissionKey,
                    ), $actor);

                    if (StockRequestResource::canViewAny()) {
                        $this->setTab('requests');
                    }
                    Notification::make()->success()->title("Stock Request {$request->reference} submitted")->send();
                }),
        ];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(NavigationPreferenceService::class)->workspaceModeEnabled($user)
            && (
                app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)
                || app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewStockRequests)
                || app(InventoryAuthorization::class)->allows($user, InventoryPermission::ViewLocationBalances)
                || app(ProductAuthorization::class)->allows($user, ProductPermission::View)
            );
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        // The tab is restored from the URL on a full browser refresh.
        // Never trust query parameters to grant access to an ERP module.
        if ($this->activeTab === 'extra') {
            if ($this->activeExtraModule === null || ! $this->extraModules()->has($this->activeExtraModule)) {
                $this->activeTab = 'overview';
                $this->activeExtraModule = null;
            }

            return;
        }

        if (! $this->isPermittedWorkspaceTab($this->activeTab)) {
            $this->activeTab = 'overview';
        }

        $this->activeExtraModule = null;
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $authorization = app(InventoryAuthorization::class);
        $canInventory = $authorization->allows($user, InventoryPermission::View);
        $canLocationBalances = $authorization->allows($user, InventoryPermission::ViewLocationBalances);
        // The location overview and low-stock analysis are only rendered on
        // Overview; avoid computing them during inventory/search/module tabs.
        $products = ($this->activeTab === 'overview' && $canInventory && $canLocationBalances)
            ? app(InventoryLocationOverviewService::class)->forUser($user, false)
            : collect();
        $inventoryQuery = $canInventory
            ? app(InventoryReadService::class)->inventories($user)
            : null;

        if ($inventoryQuery !== null && trim($this->search) !== '') {
            $needle = addcslashes(trim($this->search), '%_\\\\');
            $inventoryQuery->whereHas('product', function ($query) use ($needle): void {
                $query->where(function ($product) use ($needle): void {
                    $product->where('sku', 'like', '%'.$needle.'%')
                        ->orWhere('name', 'like', '%'.$needle.'%');
                });
            });
        }

        if ($inventoryQuery !== null) {
            // Apply this filter before pagination, preserving the original
            // responsibility scope. Staff see their own holder quantity,
            // not a company's combined warehouse quantity, for this filter.
            $ownedInventory = app(EmployeeOwnedInventoryReadService::class);
            $personalView = $ownedInventory->isEmployeeView($user);
            if ($this->inventoryStockFilter === 'in_stock') {
                if ($personalView) {
                    $inventoryQuery->whereIn('product_inventories.id', $ownedInventory->inventoryIds($user));
                } else {
                    $inventoryQuery->whereColumn('product_inventories.available_quantity', '>', 'product_inventories.reserved_quantity');
                }
            } elseif ($this->inventoryStockFilter === 'zero_stock') {
                if ($personalView) {
                    $inventoryQuery->whereNotIn('product_inventories.id', $ownedInventory->inventoryIds($user));
                } else {
                    $inventoryQuery->whereColumn('product_inventories.available_quantity', '<=', 'product_inventories.reserved_quantity');
                }
            }
        }

        $inventory = (in_array($this->activeTab, ['overview', 'inventory'], true) && $inventoryQuery !== null)
            ? $inventoryQuery->latest('product_inventories.updated_at')->paginate(15, pageName: 'inventory-page')
            : collect();

        // Movement history must remain inside BOTH the existing responsibility scope
        // and the independent inventory.view_movements Access Control grant.
        $canSeeMovements = $authorization->allows($user, InventoryPermission::ViewMovements);
        $recentMovements = $this->activeTab === 'overview' && $canSeeMovements
            ? app(InventoryReadService::class)->movements($user)->latest('occurred_at')->limit(6)->get()
            : collect();

        // The same configurable threshold used by ERP stock intelligence;
        // product data already comes from the responsibility-scoped overview.
        $lowStockThreshold = app(StockStatus::class)->low();
        $lowStockProducts = $products
            ->filter(fn (array $product): bool => (int) $product['sellable'] <= $lowStockThreshold)
            ->sortBy('sellable')
            ->take(6)
            ->values();

        // Tab listings keep the same resource visibility scopes as Classic.
        // Product search is limited to the authorized ProductResource query.
        $moduleQuery = match ($this->activeTab) {
            'products' => ProductResource::canViewAny() ? ProductResource::getEloquentQuery() : null,
            'myInventory' => ProductInventoryResource::canViewAny() ? ProductInventoryResource::getEloquentQuery()->with('product:id,sku,name') : null,
            'requests' => StockRequestResource::canViewAny() ? StockRequestResource::getEloquentQuery()
                ->with('requester:id,name')->withSum('items as requested_quantity', 'quantity') : null,
            'transfers' => StockTransferResource::canViewAny() ? StockTransferResource::getEloquentQuery() : null,
            default => null,
        };
        if ($this->activeTab === 'myInventory' && $moduleQuery !== null && trim($this->myInventorySearch) !== '') {
            $needle = addcslashes(trim($this->myInventorySearch), '%_\\\\');
            $moduleQuery->whereHas('product', function ($query) use ($needle): void {
                $query->where(function ($product) use ($needle): void {
                    $product->where('sku', 'like', '%'.$needle.'%')
                        ->orWhere('name', 'like', '%'.$needle.'%');
                });
            });
        }
        if ($this->activeTab === 'products' && $moduleQuery !== null && trim($this->productSearch) !== '') {
            $needle = addcslashes(trim($this->productSearch), '%_\\\\');
            $moduleQuery->where(function ($query) use ($needle): void {
                $query->where('sku', 'like', '%'.$needle.'%')
                    ->orWhere('name', 'like', '%'.$needle.'%');
            });
        }

        $moduleRecords = $moduleQuery?->latest()->paginate(10, pageName: 'module-page') ?? collect();

        // Reuse Classic's already permission-filtered operational cards,
        // including independently gated inventory valuation. Only compute
        // the additional snapshot on the selected Overview tab.
        $inventoryDashboard = $this->activeTab === 'overview'
            ? app(ErpDashboardService::class)->forUser(
                $user, widgetKeys: ['inventory', 'inventory_intelligence', 'attention']
            )
            : null;
        $inventoryCards = $inventoryDashboard === null
            ? collect()
            : $inventoryDashboard['cards']->keyBy('key');
        $inventoryAttention = $inventoryDashboard === null
            ? collect()
            : $inventoryDashboard['attention']->take(8)->values();

        $tabCounts = [];
        if ($canInventory) {
            $tabCounts['inventory'] = $inventory instanceof LengthAwarePaginator
                ? $inventory->total()
                : ($inventoryQuery?->count() ?? 0);
        }
        if (ProductInventoryResource::canViewAny()) {
            $tabCounts['myInventory'] = ProductInventoryResource::getEloquentQuery()->count();
        }
        if (ProductResource::canViewAny()) {
            $tabCounts['products'] = ProductResource::getEloquentQuery()->count();
        }
        if (StockRequestResource::canViewAny()) {
            $tabCounts['requests'] = StockRequestResource::getEloquentQuery()->count();
        }
        if (StockTransferResource::canViewAny()) {
            $tabCounts['transfers'] = StockTransferResource::getEloquentQuery()->count();
        }

        $selectedExtra = $this->activeTab === 'extra' && $this->activeExtraModule !== null
            ? $this->extraModules()->get($this->activeExtraModule)
            : null;
        $extraPreview = is_array($selectedExtra)
            ? app(WorkspaceRecordPreviewService::class)->forLink($selectedExtra)
            : null;

        // Inline reads reuse the same narrowly scoped services as the existing
        // modules. No allocation balances from other employees are exposed.
        $allocatedRows = collect();
        $locationRows = collect();
        $ownershipRows = collect();
        $ownershipSummaries = collect();
        $ownershipMetrics = [];
        $ownershipHolderOptions = collect();
        $ownershipBrandOptions = collect();
        $ownershipCategoryOptions = collect();
        $ownershipWarehouseOptions = collect();

        if (is_array($selectedExtra) && $selectedExtra['label'] === 'Stock Ownership'
            && StockByHolder::canAccess()) {
            // The existing report enforces Owner/Admin access. Reuse its
            // ledger-backed query and filters, never generic inventory balances.
            $report = app(StockByHolderReportService::class);
            $filters = [
                'holder_id' => $this->ownershipHolderId,
                'search' => $this->ownershipSearch,
                'brand_id' => $this->ownershipBrandId,
                'category_id' => $this->ownershipCategoryId,
                'warehouse_id' => $this->ownershipWarehouseId,
                'unassigned' => $this->ownershipUnassignedOnly,
            ];
            $ownershipSummaries = $report->holderSummaries($filters);
            $held = (int) $ownershipSummaries->sum('total_held');
            $reserved = (int) $ownershipSummaries->sum('reserved_quantity');
            $ownershipMetrics = [
                'total_held' => $held,
                'available' => $held - $reserved,
                'reserved' => $reserved,
                'models' => $report->distinctModelCount($filters),
                'unassigned' => (int) $ownershipSummaries->where('is_system', true)->sum('total_held'),
            ];
            $ownershipRows = $report->balances($filters)
                ->orderBy('account_id')->orderBy('product_inventory_id')
                ->paginate(15, pageName: 'ownership-page');
            $ownershipHolderOptions = $report->holders()->mapWithKeys(
                fn ($account): array => [$account->id => app(StockByHolder::class)->holderLabel($account)]
            );
            $ownershipBrandOptions = \App\Models\ProductBrand::query()->orderBy('name')->pluck('name', 'id');
            $ownershipCategoryOptions = \App\Models\ProductCategory::query()->orderBy('name')->pluck('name', 'id');
            $ownershipWarehouseOptions = \App\Models\Warehouse::query()->orderBy('name')->pluck('name', 'id');
        }
        if (is_array($selectedExtra) && $canInventory && $canLocationBalances) {
            if ($selectedExtra['label'] === 'My Allocated Stock') {
                $allocatedRows = app(EmployeeOwnedInventoryReadService::class)
                    ->balances($user)
                    ->where('allocated_quantity', '>', 0)
                    ->with(['inventory.product:id,sku,name', 'inventory.warehouse:id,name'])
                    ->orderByDesc('allocated_quantity')
                    ->limit(50)
                    ->get();
            } elseif ($selectedExtra['label'] === 'Inventory by Location') {
                $locationRows = app(InventoryLocationOverviewService::class)
                    ->forUser($user, false)
                    ->flatMap(fn (array $item): array => collect($item['locations'])->map(fn (array $location): array => [
                        'sku' => $item['product']->sku,
                        'product' => $item['product']->name,
                        'location' => $location['name'],
                        'available' => (int) $location['available'],
                        'reserved' => (int) $location['reserved'],
                        'sellable' => (int) $location['sellable'],
                    ])->all())
                    ->take(50)->values();
            }
        }

        $inventoryOwnBalances = collect();
        $employeeInventoryView = app(EmployeeOwnedInventoryReadService::class)->isEmployeeView($user);
        if ($employeeInventoryView && $inventory instanceof LengthAwarePaginator) {
            $ids = $inventory->getCollection()->pluck('id')->all();
            if ($ids !== []) {
                $inventoryOwnBalances = app(EmployeeOwnedInventoryReadService::class)->balances($user)
                    ->whereIn('product_inventory_id', $ids)
                    ->get(['product_inventory_id', 'allocated_quantity', 'reserved_quantity'])
                    ->keyBy('product_inventory_id');
            }
        }

        return [
            'employeeInventoryView' => $employeeInventoryView,
            'inventoryOwnBalances' => $inventoryOwnBalances,
            'quickViewInventory' => $this->loadInventoryQuickView($user, $authorization),
            'pendingExecutionRequest' => $this->pendingExecutionRequest($user),
            'pendingOwnerTransfer' => $this->pendingOwnerTransfer($user),
            'pendingSourceDecision' => $this->pendingSourceDecision($user),
            'extraPreview' => $extraPreview,
            'allocatedRows' => $allocatedRows,
            'ownershipRows' => $ownershipRows,
            'ownershipSummaries' => $ownershipSummaries,
            'ownershipMetrics' => $ownershipMetrics,
            'ownershipHolderOptions' => $ownershipHolderOptions,
            'ownershipBrandOptions' => $ownershipBrandOptions,
            'ownershipCategoryOptions' => $ownershipCategoryOptions,
            'ownershipWarehouseOptions' => $ownershipWarehouseOptions,
            'locationRows' => $locationRows,
            'inventoryCards' => $inventoryCards,
            'inventoryAttention' => $inventoryAttention,
            'tabCounts' => $tabCounts,
            'moduleRecords' => $moduleRecords,
            'inventory' => $inventory,
            'recentMovements' => $recentMovements,
            'lowStockProducts' => $lowStockProducts,
            'lowStockThreshold' => $lowStockThreshold,
            'summary' => [
                'products' => $products->count(),
                'available' => $products->sum('available'),
                'reserved' => $products->sum('reserved'),
                'allocated' => $products->sum('total_owned'),
            ],
            'canSeeSummary' => $canInventory && $canLocationBalances,
            'extraModules' => $this->extraModules(),
            'links' => array_filter([
                'products' => ProductResource::canViewAny() ? ProductResource::getUrl() : null,
                'createProduct' => ProductResource::canCreate() ? ProductResource::getUrl('create') : null,
                'inventory' => InventoryOverview::canAccess() ? InventoryOverview::getUrl() : null,
                'myInventory' => ProductInventoryResource::canViewAny() ? ProductInventoryResource::getUrl() : null,
                'requests' => StockRequestResource::canViewAny() ? StockRequestResource::getUrl() : null,
                'transfers' => StockTransferResource::canViewAny() ? StockTransferResource::getUrl() : null,
                'createTransfer' => StockTransferResource::canCreate() ? StockTransferResource::getUrl('create') : null,
                'adjustments' => StockAdjustments::canAccess() ? StockAdjustments::getUrl() : null,
                'movements' => $canSeeMovements && StockMovementResource::canViewAny() ? StockMovementResource::getUrl() : null,
            ]),
            'canViewProducts' => ProductResource::canViewAny(),
        ];
    }

    public function openInventoryQuickView(int $inventoryId): void
    {
        abort_unless(static::canAccess() && $inventoryId > 0, 403);
        $user = auth()->user();
        abort_unless($user instanceof User
            && app(InventoryAuthorization::class)->allows($user, InventoryPermission::View), 403);

        // Check record-level responsibility every time, including on the
        // action request; never trust an inventory ID sent by the browser.
        abort_unless(app(InventoryReadService::class)->inventories($user)
            ->whereKey($inventoryId)->exists(), 404);

        $this->quickViewInventoryId = $inventoryId;
    }

    public function closeInventoryQuickView(): void
    {
        $this->quickViewInventoryId = null;
    }

    private function loadInventoryQuickView(User $user, InventoryAuthorization $authorization): ?array
    {
        if ($this->quickViewInventoryId === null) {
            return null;
        }

        if ($this->quickViewInventoryId < 1 || ! static::canAccess()
            || ! $authorization->allows($user, InventoryPermission::View)) {
            $this->closeInventoryQuickView();

            return null;
        }

        $inventory = app(InventoryReadService::class)->inventories($user)
            ->whereKey($this->quickViewInventoryId)->first();

        if ($inventory === null) {
            $this->closeInventoryQuickView();

            return null;
        }

        // Movements have an independent grant and their own responsibility
        // scope. No financial costs or cross-employee allocations are loaded.
        $movements = $authorization->allows($user, InventoryPermission::ViewMovements)
            ? app(InventoryReadService::class)->movements($user)
                ->where('product_inventory_id', $inventory->id)
                ->latest('occurred_at')->limit(5)->get()
            : collect();

        $product = $inventory->product;
        $mayViewProduct = $product !== null
            && ProductResource::canViewAny()
            && ProductResource::canView($product);

        return [
            'sku' => $product?->sku ?? '—',
            'name' => $product?->name ?? '—',
            'warehouse' => $inventory->warehouse?->name ?? '—',
            'available' => (int) $inventory->available_quantity,
            'reserved' => (int) $inventory->reserved_quantity,
            'damaged' => (int) $inventory->damaged_quantity,
        $reservationQuery = InventoryReservation::query()
            ->where('product_inventory_id', $inventory->id)
            ->where('status', InventoryReservationStatus::Active);

        // Staff only see reservation details backed by their own
        // allocation account; do not disclose another employee's order.
        if (app(EmployeeOwnedInventoryReadService::class)->isEmployeeView($user)) {
            $reservationQuery->whereHas('allocationLines.account', fn ($account) => $account
                ->where('type', 'employee')->where('employee_id', $user->employee?->id ?? 0));
        }
        $activeReservations = $reservationQuery->orderByDesc('id')->limit(5)->get();
        $reservationRows = $activeReservations->map(function (InventoryReservation $reservation) use ($user): array {
            $ownLines = $reservation->allocationLines()->whereHas('account', fn ($account) => $account
                ->where('type', 'employee')->where('employee_id', $user->employee?->id ?? 0));
            $quantity = app(EmployeeOwnedInventoryReadService::class)->isEmployeeView($user)
                ? (int) $ownLines->sum('quantity')
                : (int) $reservation->quantity;

            return [
                'reference' => 'Reservation #'.$reservation->id,
                'quantity' => $quantity,
                'reserved_at' => $reservation->reserved_at?->format('d M Y, H:i') ?? '—',
            ];
        })->all();

            'reservationRows' => $reservationRows,
            'movements' => $movements->map(fn ($movement): array => [
                'reference' => $movement->reference ?? ('#'.$movement->id),
                'type' => $movement->movement_type?->label() ?? 'Movement',
                'quantity' => (int) $movement->quantity,
                'date' => $movement->occurred_at?->format('d M Y, H:i') ?? '—',
            ])->all(),
            'movementsAllowed' => $authorization->allows($user, InventoryPermission::ViewMovements),
            'productUrl' => $mayViewProduct
                ? ProductResource::getUrl('view', ['record' => $product])
                : null,
        ];
    }

    /**
     * The request list remains scoped by StockRequestResource, then each
     * transactional action re-checks the request visibility and Execute grant.
     */
    public function prepareStockRequestExecution(int $requestId): void
    {
        $this->authorizedExecutableRequest($requestId);
        $this->pendingExecutionRequestId = $requestId;
    }

    public function cancelStockRequestExecution(): void
    {
        $this->pendingExecutionRequestId = null;
    }

    public function executePreparedStockRequest(): void
    {
        abort_unless($this->pendingExecutionRequestId !== null, 403);
        $request = $this->authorizedExecutableRequest($this->pendingExecutionRequestId);
        $this->pendingExecutionRequestId = null;

        try {
            $execution = app(StockRequestExecutionService::class)->execute(
                $request, (string) str()->uuid(), auth()->user()
            );
            if ($execution instanceof StockRequestExecution) {
                Notification::make()->success()->title('Stock Request executed successfully')->send();
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()->warning()->title('Stock Request execution was not completed')
                ->body(collect($e->errors())->flatten()->first() ?? 'Review the request before retrying.')->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Stock Request execution failed')
                ->body('Open the full request to review the issue.')->send();
        }
    }

    public function canExecuteWorkspaceRequest(StockRequest $request): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && StockRequestResource::canView($request)
            && app(StockRequestExecutionService::class)->canExecute($user, $request);
    }

    private function authorizedExecutableRequest(int $requestId): StockRequest
    {
        abort_unless(static::canAccess()
            && $requestId > 0
            && StockRequestResource::canViewAny(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($requestId)->first();
        abort_unless($request instanceof StockRequest, 404);
        abort_unless(app(StockRequestService::class)->canView($user, $request), 403);
        abort_unless(app(StockRequestExecutionService::class)->canExecute($user, $request), 403);

        return $request;
    }

    private function pendingExecutionRequest(User $user): ?array
    {
        if ($this->pendingExecutionRequestId === null
            || ! StockRequestResource::canViewAny()) {
            return null;
        }

        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($this->pendingExecutionRequestId)->first();
        if ($request === null || ! app(StockRequestService::class)->canView($user, $request)
            || ! app(StockRequestExecutionService::class)->canExecute($user, $request)) {
            $this->pendingExecutionRequestId = null;

            return null;
        }

        return [
            'reference' => $request->reference,
            'summary' => app(StockRequestExecutionService::class)->summary($request),
        ];
    }

    /**
     * Only individual source-line decisions are surfaced here. The existing
     * StockRequestApprovalService retains ownership, availability, locking,
     * idempotency, and audit enforcement.
     */
    public function prepareSourceDecision(int $requestId): void
    {
        $request = $this->authorizedApprovalRequest($requestId);
        $eligible = app(StockRequestApprovalService::class)
            ->eligiblePendingLines($request, auth()->user());

        abort_unless($eligible->isNotEmpty(), 403);

        $this->pendingSourceDecisionRequestId = $requestId;
        $this->pendingSourceDecisionLineId = null;
        $this->sourceDecision = StockRequestSourceStatus::Approved->value;
        $this->sourceApprovedQuantity = 1;
        $this->sourceDecisionNote = '';
    }

    public function cancelSourceDecision(): void
    {
        $this->pendingSourceDecisionRequestId = null;
        $this->pendingSourceDecisionLineId = null;
        $this->sourceDecisionNote = '';
    }

    public function submitSourceDecision(): void
    {
        abort_unless($this->pendingSourceDecisionRequestId !== null
            && $this->pendingSourceDecisionLineId !== null, 403);

        $request = $this->authorizedApprovalRequest($this->pendingSourceDecisionRequestId);
        $line = app(StockRequestApprovalService::class)
            ->eligiblePendingLines($request, auth()->user())
            ->firstWhere('id', $this->pendingSourceDecisionLineId);
        abort_unless($line instanceof StockRequestSourceLine, 403);
        abort_unless(in_array($this->sourceDecision, [
            StockRequestSourceStatus::Approved->value, StockRequestSourceStatus::Rejected->value,
        ], true), 403);

        $decision = StockRequestSourceStatus::from($this->sourceDecision);
        $note = trim($this->sourceDecisionNote);
        $quantity = $decision === StockRequestSourceStatus::Approved
            ? $this->sourceApprovedQuantity : null;

        try {
            app(StockRequestApprovalService::class)->decide(
                $line, $decision, $note === '' ? null : $note,
                (string) str()->uuid(), auth()->user(), $quantity
            );
            $this->cancelSourceDecision();
            Notification::make()->success()->title(
                $decision === StockRequestSourceStatus::Approved
                    ? 'Stock source approval recorded' : 'Stock source rejection recorded'
            )->send();
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()->warning()->title('Stock source decision was not recorded')
                ->body(collect($e->errors())->flatten()->first() ?? 'Review the quantity or reason.')->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Stock source decision failed')
                ->body('Open the full Stock Request for details.')->send();
        }
    }

    public function canApproveWorkspaceRequest(StockRequest $request): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && StockRequestResource::canView($request)
            && app(StockRequestApprovalService::class)
                ->eligiblePendingLines($request, $user)->isNotEmpty();
    }

    private function authorizedApprovalRequest(int $requestId): StockRequest
    {
        abort_unless(static::canAccess() && $requestId > 0
            && StockRequestResource::canViewAny(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($requestId)->first();
        abort_unless($request instanceof StockRequest, 404);
        abort_unless(app(StockRequestService::class)->canView($user, $request), 403);

        return $request;
    }

    private function pendingSourceDecision(User $user): ?array
    {
        if ($this->pendingSourceDecisionRequestId === null
            || ! StockRequestResource::canViewAny()) {
            return null;
        }
        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($this->pendingSourceDecisionRequestId)->first();
        if ($request === null || ! app(StockRequestService::class)->canView($user, $request)) {
            $this->cancelSourceDecision();

            return null;
        }
        $eligible = app(StockRequestApprovalService::class)
            ->eligiblePendingLines($request, $user);
        if ($eligible->isEmpty()) {
            $this->cancelSourceDecision();

            return null;
        }

        return [
            'reference' => $request->reference,
            'options' => $eligible->mapWithKeys(fn (StockRequestSourceLine $line): array => [
                $line->id => $line->item->sku.' — '.$line->source_label
                    .' — '.max(0, (int) $line->proposed_quantity - (int) $line->approved_quantity).' remaining',
            ])->all(),
        ];
    }

    /**
     * Owner-only combined approval and transfer for permanent Stock Requests.
     * Pending quantities default to ZERO, so previously approved quantities
     * never silently approve other sources.
     */
    public function prepareOwnerApproveTransfer(int $requestId): void
    {
        $request = $this->authorizedOwnerTransferRequest($requestId);
        $this->pendingOwnerTransferRequestId = $request->id;
        $this->ownerTransferQuantities = $request->sourceLines()
            ->where('status', StockRequestSourceStatus::Pending->value)
            ->orderBy('id')->pluck('id')->mapWithKeys(fn (int $id): array => [$id => 0])->all();
    }

    public function cancelOwnerApproveTransfer(): void
    {
        $this->pendingOwnerTransferRequestId = null;
        $this->ownerTransferQuantities = [];
    }

    public function submitOwnerApproveTransfer(): void
    {
        abort_unless($this->pendingOwnerTransferRequestId !== null, 403);
        $request = $this->authorizedOwnerTransferRequest($this->pendingOwnerTransferRequestId);

        // Validate every submitted source against this exact request before
        // passing it to the existing transaction/locking implementation.
        $pending = $request->sourceLines()
            ->where('status', StockRequestSourceStatus::Pending->value)
            ->get()->keyBy('id');
        $quantities = [];
        foreach ($this->ownerTransferQuantities as $lineId => $rawQuantity) {
            abort_unless(ctype_digit((string) $lineId) && $pending->has((int) $lineId), 403);
            if (filter_var($rawQuantity, FILTER_VALIDATE_INT) === false || (int) $rawQuantity < 0) {
                Notification::make()->warning()->title('Invalid approval quantity')
                    ->body('Enter a whole number of zero or more.')->send();

                return;
            }
            $line = $pending->get((int) $lineId);
            $remaining = max(0, (int) $line->proposed_quantity - (int) $line->approved_quantity);
            if ((int) $rawQuantity > $remaining) {
                Notification::make()->warning()->title('Approval exceeds requested quantity')
                    ->body('Review the remaining quantity for each source.')->send();

                return;
            }
            if ((int) $rawQuantity > 0) {
                $quantities[(int) $lineId] = (int) $rawQuantity;
            }
        }

        $alreadyApproved = $request->sourceLines()->where('approved_quantity', '>', 0)
            ->whereColumn('approved_quantity', '>', 'transferred_quantity')->exists();
        if ($quantities === [] && ! $alreadyApproved) {
            Notification::make()->warning()->title('No stock selected')
                ->body('Enter a quantity for a pending source or approve stock first.')->send();

            return;
        }

        try {
            $execution = app(StockRequestExecutionService::class)->approveAndTransfer(
                $request, (string) str()->uuid(), auth()->user(), $quantities
            );
            $this->cancelOwnerApproveTransfer();
            if ($execution instanceof StockRequestExecution) {
                Notification::make()->success()->title('Approved stock transferred successfully')->send();
            }
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            Notification::make()->warning()->title('Approve & Transfer not completed')
                ->body(collect($e->errors())->flatten()->first() ?? 'Review the request quantities.')->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->danger()->title('Approve & Transfer failed')
                ->body('Open the full Stock Request for details.')->send();
        }
    }

    public function canOwnerApproveTransfer(StockRequest $request): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && StockRequestResource::canView($request)
            && app(StockRequestExecutionService::class)->canApproveAndTransfer($user, $request);
    }

    private function authorizedOwnerTransferRequest(int $requestId): StockRequest
    {
        abort_unless(static::canAccess() && $requestId > 0
            && StockRequestResource::canViewAny(), 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($requestId)->first();
        abort_unless($request instanceof StockRequest, 404);
        abort_unless(app(StockRequestService::class)->canView($user, $request), 403);
        abort_unless(app(StockRequestExecutionService::class)->canApproveAndTransfer($user, $request), 403);

        return $request;
    }

    private function pendingOwnerTransfer(User $user): ?array
    {
        if ($this->pendingOwnerTransferRequestId === null || ! StockRequestResource::canViewAny()) {
            return null;
        }
        $request = app(StockRequestService::class)->visibleQuery($user)
            ->whereKey($this->pendingOwnerTransferRequestId)->first();
        if ($request === null || ! app(StockRequestService::class)->canView($user, $request)
            || ! app(StockRequestExecutionService::class)->canApproveAndTransfer($user, $request)) {
            $this->cancelOwnerApproveTransfer();

            return null;
        }

        return [
            'reference' => $request->reference,
            'alreadyApproved' => $request->sourceLines()
                ->get()->sum(fn (StockRequestSourceLine $line): int => max(0, (int) $line->approved_quantity - (int) $line->transferred_quantity)),
            'lines' => $request->sourceLines()->with('item')
                ->where('status', StockRequestSourceStatus::Pending->value)->orderBy('id')->get()
                ->map(fn (StockRequestSourceLine $line): array => [
                    'id' => $line->id,
                    'label' => $line->item->sku.' — '.$line->source_label,
                    'remaining' => max(0, (int) $line->proposed_quantity - (int) $line->approved_quantity),
                ])->all(),
        ];
    }

    private function extraModules(): Collection
    {
        return collect(app(NavigationPreferenceService::class)->workspaceModuleLinks(auth()->user(), 'Inventory & Warehouse'))
            ->reject(fn (array $item): bool => in_array($item['label'], [
                'Products', 'Inventory Overview', 'My Inventory',
                'Stock Requests', 'Stock Transfers', 'Stock Adjustments',
                'Inventory Workspace',
            ], true))
            ->values();
    }

    public function updatedOwnershipHolderId(): void { $this->resetPage('ownership-page'); }
    public function updatedOwnershipSearch(): void { $this->resetPage('ownership-page'); }
    public function updatedOwnershipBrandId(): void { $this->resetPage('ownership-page'); }
    public function updatedOwnershipCategoryId(): void { $this->resetPage('ownership-page'); }
    public function updatedOwnershipWarehouseId(): void { $this->resetPage('ownership-page'); }
    public function updatedOwnershipUnassignedOnly(): void { $this->resetPage('ownership-page'); }

    public function chooseOwnershipHolder(int $accountId): void
    {
        abort_unless(static::canAccess() && StockByHolder::canAccess(), 403);
        $this->ownershipHolderId = (string) $accountId;
        $this->ownershipUnassignedOnly = false;
        $this->resetPage('ownership-page');
    }

    public function resetOwnershipFilters(): void
    {
        abort_unless(static::canAccess() && StockByHolder::canAccess(), 403);
        $this->reset(
            'ownershipHolderId', 'ownershipSearch', 'ownershipBrandId',
            'ownershipCategoryId', 'ownershipWarehouseId', 'ownershipUnassignedOnly'
        );
        $this->resetPage('ownership-page');
    }

    public function selectExtraModule(int $index): void
    {
        abort_unless(static::canAccess() && $this->extraModules()->has($index), 403);
        $this->activeTab = 'extra';
        $this->activeExtraModule = $index;
    }

    public function setTab(string $tab): void
    {
        // Both clicks and refresh restoration enforce the same access rules.
        abort_unless($this->isPermittedWorkspaceTab($tab), 403);

        $this->activeTab = $tab;
        $this->activeExtraModule = null;
        $this->resetPage('inventory-page');
        $this->resetPage('module-page');
    }

    private function isPermittedWorkspaceTab(string $tab): bool
    {
        if (! static::canAccess()) {
            return false;
        }

        if ($tab === 'overview') {
            return true;
        }

        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return match ($tab) {
            'products' => ProductResource::canViewAny(),
            'inventory' => InventoryOverview::canAccess(),
            'myInventory' => ProductInventoryResource::canViewAny(),
            'requests' => StockRequestResource::canViewAny(),
            'transfers' => StockTransferResource::canViewAny(),
            'adjustments' => StockAdjustments::canAccess(),
            default => false,
        };
    }

    public function updatedInventoryStockFilter(): void
    {
        $this->resetPage('inventory-page');
    }

    public function updatedSearch(): void
    {
        $this->resetPage('inventory-page');
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage('module-page');
    }

    public function updatedMyInventorySearch(): void
    {
        $this->resetPage('module-page');
    }

    public function clearMyInventorySearch(): void
    {
        abort_unless(static::canAccess() && ProductInventoryResource::canViewAny(), 403);
        $this->myInventorySearch = '';
        $this->resetPage('module-page');
    }
}
