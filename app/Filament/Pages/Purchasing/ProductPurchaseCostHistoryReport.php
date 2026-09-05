<?php

namespace App\Filament\Pages\Purchasing;

use App\DTOs\Purchases\PurchaseCostHistoryFilterData;
use App\Enums\PurchasePermission;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseCostHistoryService;
use App\Support\AedMoney;

class ProductPurchaseCostHistoryReport extends BasePurchaseReport
{
    protected static ?string $navigationLabel = 'Product Purchase Cost History';

    public ?string $supplierId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::ViewCostHistory);
    }

    public function rows(): array
    {
        return app(PurchaseCostHistoryService::class)->query(new PurchaseCostHistoryFilterData(
            supplierId: $this->supplierId === 'none' ? 'none' : ($this->supplierId ? (int) $this->supplierId : null),
        ), auth()->user())->get()->map(function ($row): array {
            $values = (array) $row;
            $values['inventory_unit_cost'] = AedMoney::format((string) $values['inventory_unit_cost']);

            return $values;
        })->all();
    }

    public function filterDefinitions(): array
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return [
            'supplierId' => [
                'label' => 'Supplier',
                'type' => 'select',
                'options' => ['none' => 'No Supplier'] + app(PurchaseCostHistoryService::class)->supplierOptions($user),
            ],
        ];
    }
}
