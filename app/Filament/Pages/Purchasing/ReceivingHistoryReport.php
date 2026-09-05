<?php

namespace App\Filament\Pages\Purchasing;

use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ReceivingHistoryReport extends BasePurchaseReport
{
    protected string $view = 'filament.pages.receiving-history-report';

    protected static ?string $navigationLabel = 'Receiving History';

    public ?string $dateFrom = null;

    public ?string $dateTo = null;

    public ?string $supplierId = null;

    public ?string $warehouseId = null;

    public ?string $productId = null;

    public function rows(): array
    {
        abort_unless(static::canAccess(), 403);

        return DB::table('purchase_receipts as pr')
            ->join('purchases as purchase', 'purchase.id', '=', 'pr.purchase_id')
            ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'purchase.supplier_id')
            ->join('warehouses as warehouse', 'warehouse.id', '=', 'pr.warehouse_id')
            ->join('users as receiver', 'receiver.id', '=', 'pr.received_by_user_id')
            ->join('purchase_receipt_items as pri', 'pri.purchase_receipt_id', '=', 'pr.id')
            ->select([
                'pr.id', 'pr.reference as grn', 'purchase.reference as purchase', DB::raw("COALESCE(supplier.name, 'No Supplier') as supplier"),
                'warehouse.code as warehouse', 'pr.received_at', 'receiver.name as received_by',
            ])
            ->selectRaw('SUM(pri.accepted_quantity) AS accepted_quantity')
            ->selectRaw('SUM(pri.damaged_quantity) AS damaged_quantity')
            ->selectRaw('SUM(pri.rejected_quantity) AS rejected_quantity')
            ->when($this->dateFrom, fn ($query, string $date) => $query->whereDate('pr.received_at', '>=', $date))
            ->when($this->dateTo, fn ($query, string $date) => $query->whereDate('pr.received_at', '<=', $date))
            ->when($this->supplierId === 'none', fn ($query) => $query->whereNull('purchase.supplier_id'))
            ->when($this->supplierId && $this->supplierId !== 'none', fn ($query, string $id) => $query->where('purchase.supplier_id', (int) $id))
            ->when($this->warehouseId, fn ($query, string $id) => $query->where('pr.warehouse_id', (int) $id))
            ->when($this->productId, fn ($query, string $id) => $query->where('pri.product_id', (int) $id))
            ->groupBy('pr.id', 'pr.reference', 'purchase.reference', 'supplier.name', 'warehouse.code', 'pr.received_at', 'receiver.name')
            ->orderByDesc('pr.received_at')->orderByDesc('pr.id')
            ->get()
            ->map(fn ($row): array => [
                'grn' => $row->grn,
                'purchase' => $row->purchase,
                'supplier' => $row->supplier,
                'warehouse' => $row->warehouse,
                'received_at' => CarbonImmutable::parse($row->received_at)->timezone(config('app.timezone'))->format('d M Y, h:i A'),
                'received_by' => $row->received_by,
                'accepted_quantity' => (int) $row->accepted_quantity,
                'damaged_quantity' => (int) $row->damaged_quantity,
                'rejected_quantity' => (int) $row->rejected_quantity,
                'view' => PurchaseReceiptResource::getUrl('view', ['record' => $row->id]),
            ])->all();
    }

    public function filterDefinitions(): array
    {
        return [
            'dateFrom' => ['label' => 'Received from', 'type' => 'date'],
            'dateTo' => ['label' => 'Received to', 'type' => 'date'],
            'supplierId' => ['label' => 'Supplier', 'type' => 'select', 'options' => ['none' => 'No Supplier'] + Supplier::query()->orderBy('name')->pluck('name', 'id')->all()],
            'warehouseId' => ['label' => 'Warehouse', 'type' => 'select', 'options' => Warehouse::query()->orderBy('name')->get()->mapWithKeys(fn (Warehouse $warehouse): array => [$warehouse->id => "{$warehouse->name} ({$warehouse->code})"])->all()],
            'productId' => ['label' => 'Product', 'type' => 'select', 'options' => Product::query()->orderBy('name')->get()->mapWithKeys(fn (Product $product): array => [$product->id => "{$product->sku} — {$product->name}"])->all()],
        ];
    }
}
