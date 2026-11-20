<?php

namespace App\Filament\Pages;

use App\Actions\Products\CreateProduct as CreateProductAction;
use App\DTOs\Products\CreateProductData;
use App\Filament\Pages\Purchasing\ExpectedDeliveriesReport;
use App\Filament\Pages\Purchasing\OpenPurchasesReport;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\On;
use Livewire\WithPagination;

class PurchasingWorkspace extends BaseOperationalWorkspace
{
    use WithPagination;

    public string $productSearch = '';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('quickPurchase')
                ->label('Quick Purchase')->modalHeading('Quick Purchase')->modalWidth('6xl')
                ->visible(fn (): bool => static::canAccess() && QuickStockPurchase::canAccess())
                ->modalSubmitAction(false)
                ->modalContent(fn () => view('filament.pages.partials.workspace-quick-purchase')),
            Action::make('createPurchasingProduct')
                ->label('Add Product')
                ->modalHeading('Add Product')
                ->modalWidth('5xl')
                ->schema(ProductForm::components())
                ->visible(fn (): bool => static::canAccess() && ProductResource::canCreate())
                ->modalSubmitActionLabel('Save Product')
                ->action(function (array $data): void {
                    abort_unless(static::canAccess() && ProductResource::canCreate(), 403);
                    app(CreateProductAction::class)->handle(new CreateProductData(
                        name: $data['name'],
                        brandId: (int) $data['brand_id'],
                        categoryId: (int) $data['category_id'],
                        condition: $data['condition'],
                        model: $data['model'] ?? null,
                        processor: $data['processor'] ?? null,
                        processorClass: $data['processor_class'] ?? null,
                        processorModel: $data['processor_model'] ?? null,
                        processorGeneration: $data['processor_generation'] ?? null,
                        ram: $data['ram'] ?? null,
                        storage: $data['storage'] ?? null,
                        screenSize: $data['screen_size'] ?? null,
                        graphics: $data['graphics'] ?? null,
                        color: $data['color'] ?? null,
                        touchScreen: filled($data['touch_screen'] ?? null) ? (bool) $data['touch_screen'] : null,
                        isConvertible360: filled($data['is_convertible_360'] ?? null) ? (bool) $data['is_convertible_360'] : null,
                        accountingTitleOverride: $data['accounting_title_override'] ?? null,
                        websiteTitleOverride: $data['website_title_override'] ?? null,
                        warranty: $data['warranty'],
                        costPrice: $data['cost_price'] ?? null,
                        costPriceProvided: array_key_exists('cost_price', $data),
                        sellingPrice: $data['selling_price'] ?? null,
                        sellingPriceProvided: array_key_exists('selling_price', $data),
                        description: $data['description'] ?? null,
                        duplicateOverrideReason: $data['duplicate_override_reason'] ?? null,
                        duplicateOverrideConfirmed: (bool) ($data['duplicate_override_confirmed'] ?? false),
                        responsibilityOverrideConfirmed: (bool) ($data['responsibility_override_confirmed'] ?? false),
                    ), auth()->user());
                    $this->activeModule = 'products';
                    $this->resetPage('purchasing-products');
                    Notification::make()->success()->title('Product added to catalog')->send();
                }),

        ];
    }

    #[On('workspace-purchase-created')]
    public function purchaseCreated(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->unmountAction();
    }

    public function selectProducts(): void
    {
        abort_unless(static::canAccess() && ProductResource::canViewAny(), 403);
        $this->activeModule = 'products';
    }

    public function updatedProductSearch(): void
    {
        $this->resetPage('purchasing-products');
    }

    protected function getViewData(): array
    {
        return parent::getViewData() + [
            'purchasingProductCount' => ProductResource::canViewAny() ? ProductResource::getEloquentQuery()->count() : 0,
            'purchasingProducts' => $this->activeModule === 'products' && ProductResource::canViewAny()
                ? ProductResource::getEloquentQuery()
                    ->when($this->productSearch !== '', fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$this->productSearch.'%')->orWhere('sku', 'like', '%'.$this->productSearch.'%')))
                    ->orderByDesc('id')->paginate(10, pageName: 'purchasing-products')
                : collect(),
        ];
    }

    /** Quick Stock Purchase is opened from the header modal; do not duplicate it as a workspace tab. */
    protected static function authorizedModuleTabs(): \Illuminate\Support\Collection
    {
        return parent::authorizedModuleTabs()
            ->reject(fn (array $tab): bool => ($tab['class'] ?? null) === QuickStockPurchase::class)
            ->values();
    }

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|\UnitEnum|null $navigationGroup = 'Purchasing';

    protected static ?string $navigationLabel = 'Purchasing Workspace';

    protected static ?string $slug = 'purchasing-workspace';

    protected static function metricResources(): array
    {
        return [
            ['label' => 'Purchase Orders', 'class' => PurchaseResource::class, 'icon' => 'heroicon-o-shopping-cart'],
            ['label' => 'Goods Receipts', 'class' => PurchaseReceiptResource::class, 'icon' => 'heroicon-o-archive-box-arrow-down'],
        ];
    }

    protected static function recentResources(): array
    {
        return [PurchaseResource::class];
    }

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Purchasing Workspace',
            'eyebrow' => 'Suppliers & stock acquisition',
            'description' => 'Purchase orders, receipts, sourcing and deliveries in your authorized scope.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
                [
                    'title' => 'Purchase management',
                    'description' => 'Continue purchasing operations using existing approval and receiving rules',
                    'links' => [
                        ['label' => 'Purchase Orders', 'class' => PurchaseResource::class, 'icon' => 'heroicon-o-shopping-cart'],
                        ['label' => 'Goods Receipts', 'class' => PurchaseReceiptResource::class, 'icon' => 'heroicon-o-archive-box-arrow-down'],
                        ['label' => 'Suppliers', 'class' => SupplierResource::class, 'icon' => 'heroicon-o-building-office-2'],
                    ],
                ],
                [
                    'title' => 'Receiving & reports',
                    'description' => 'Authorized sourcing and purchasing intelligence',
                    'links' => [
                        ['label' => 'Quick Stock Purchase', 'class' => QuickStockPurchase::class, 'icon' => 'heroicon-o-plus-circle'],
                        ['label' => 'Open Purchases', 'class' => OpenPurchasesReport::class, 'icon' => 'heroicon-o-clock'],
                        ['label' => 'Expected Deliveries', 'class' => ExpectedDeliveriesReport::class, 'icon' => 'heroicon-o-truck'],
                    ],
                ],
            ],
        ];
    }
}
