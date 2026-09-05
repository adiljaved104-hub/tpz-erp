<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseExportData;
use App\Enums\PurchasePermission;
use App\Models\Purchase;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\PurchaseAuthorization;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseExportService
{
    private const BASE_FIELDS = ['reference', 'supplier', 'warehouse_id', 'supplier_invoice_number', 'purchase_date', 'expected_delivery_date', 'external_accounting_reference', 'status', 'currency'];

    private const FINANCIAL_FIELDS = ['subtotal', 'discount_total', 'net_before_vat', 'shipping_total', 'other_charges_total', 'vat_total', 'grand_total'];

    public function __construct(private readonly PurchaseAuthorization $authorization, private readonly ActivityLogger $activity) {}

    public function stream(PurchaseExportData $data, User $actor): StreamedResponse
    {
        $this->authorization->authorize($actor, PurchasePermission::Export);
        $fields = self::BASE_FIELDS;

        if ($this->authorization->allows($actor, PurchasePermission::ViewFinancials)) {
            $fields = array_merge($fields, self::FINANCIAL_FIELDS);
        }

        $selects = collect($fields)->map(fn (string $field): mixed => $field === 'supplier'
            ? DB::raw("COALESCE(suppliers.name, 'No Supplier') AS supplier")
            : "purchases.{$field}")->all();
        $query = Purchase::query()->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')->select($selects)
            ->when($data->status, fn ($query, $value) => $query->where('purchases.status', $value))
            ->when($data->supplierId === 'none', fn ($query) => $query->whereNull('purchases.supplier_id'))
            ->when($data->supplierId && $data->supplierId !== 'none', fn ($query, $value) => $query->where('purchases.supplier_id', $value))
            ->when($data->warehouseId, fn ($query, $value) => $query->where('purchases.warehouse_id', $value))
            ->when($data->dateFrom, fn ($query, $value) => $query->whereDate('purchases.purchase_date', '>=', $value))
            ->when($data->dateTo, fn ($query, $value) => $query->whereDate('purchases.purchase_date', '<=', $value))->orderBy('purchases.id');
        $rowCount = (clone $query)->count();

        return response()->streamDownload(function () use ($query, $fields, $rowCount, $data, $actor): void {
            $output = fopen('php://output', 'wb');
            throw_if($output === false, \RuntimeException::class, 'Unable to open Purchase export stream.');
            fputcsv($output, $fields);
            foreach ($query->cursor() as $purchase) {
                fputcsv($output, array_map(fn (string $field): mixed => $purchase->getRawOriginal($field), $fields));
            }
            fclose($output);
            $this->activity->log('purchase.exported', $actor, properties: [
                'filters' => $data->filters(), 'exported_row_count' => $rowCount, 'included_fields' => $fields,
            ]);
        }, 'purchases-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
