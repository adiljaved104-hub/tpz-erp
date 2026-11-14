<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Purchasing\ExpectedDeliveriesReport;
use App\Filament\Pages\Purchasing\OpenPurchasesReport;
use App\Filament\Pages\Purchasing\QuickStockPurchase;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use Filament\Support\Icons\Heroicon;

class PurchasingWorkspace extends BaseOperationalWorkspace
{
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
