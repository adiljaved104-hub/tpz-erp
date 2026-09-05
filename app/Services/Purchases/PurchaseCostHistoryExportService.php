<?php

namespace App\Services\Purchases;

use App\DTOs\Purchases\PurchaseCostHistoryFilterData;
use App\Models\User;
use App\Services\ActivityLogger;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PurchaseCostHistoryExportService
{
    private const FIELDS = ['product', 'sku', 'warehouse', 'received_at', 'supplier', 'purchase_reference', 'grn_reference', 'accepted_quantity', 'damaged_quantity', 'valuation_quantity', 'inventory_unit_cost'];

    public function __construct(private readonly PurchaseCostHistoryService $history, private readonly ActivityLogger $activity) {}

    public function stream(PurchaseCostHistoryFilterData $filters, User $actor): StreamedResponse
    {
        $query = $this->history->query($filters, $actor);
        $rowCount = (clone $query)->count();

        return response()->streamDownload(function () use ($query, $filters, $rowCount, $actor): void {
            $output = fopen('php://output', 'wb');
            throw_if($output === false, \RuntimeException::class, 'Unable to open Purchase cost-history export stream.');
            fputcsv($output, self::FIELDS);
            foreach ($query->cursor() as $row) {
                fputcsv($output, array_map(fn (string $field): mixed => $row->{$field}, self::FIELDS));
            }
            fclose($output);
            $this->activity->log('purchase.cost_history_exported', $actor, properties: [
                'filters' => $filters->filters(), 'exported_row_count' => $rowCount, 'included_fields' => self::FIELDS,
            ]);
        }, 'purchase-cost-history-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv']);
    }
}
