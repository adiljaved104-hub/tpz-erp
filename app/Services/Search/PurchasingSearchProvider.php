<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Enums\PurchasePermission;
use App\Filament\Resources\PurchaseReceipts\PurchaseReceiptResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Purchases\PurchaseReadService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PurchasingSearchProvider implements GlobalSearchProvider
{
    public function search(User $user, string $query, int $limit): Collection
    {
        $authorization = app(PurchaseAuthorization::class);
        $results = collect();

        if ($authorization->allows($user, PurchasePermission::View)) {
            $ids = app(PurchaseReadService::class)->purchases($user)->select('purchases.id');
            $purchases = DB::table('purchases')->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->whereIn('purchases.id', $ids)->select(['purchases.id', 'purchases.reference', 'purchases.supplier_invoice_number', 'purchases.status', 'suppliers.name as supplier']);
            SearchQuery::match($purchases, ['purchases.reference', 'purchases.supplier_invoice_number', 'suppliers.name'], $query);
            SearchQuery::rank($purchases, 'purchases.reference', $query);
            SearchQuery::rank($purchases, 'suppliers.name', $query);
            $results = $results->concat($purchases->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
                'Purchase Orders', $row->reference.($row->supplier ? ' · '.$row->supplier : ''),
                ($row->supplier_invoice_number ? 'Invoice: '.$row->supplier_invoice_number.' · ' : '').ucfirst($row->status),
                PurchaseResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-shopping-cart',
            )));
        }

        if ($authorization->allows($user, PurchasePermission::ViewReceipts)) {
            $receipts = DB::table('purchase_receipts')->join('purchases', 'purchases.id', '=', 'purchase_receipts.purchase_id')
                ->leftJoin('suppliers', 'suppliers.id', '=', 'purchases.supplier_id')
                ->select(['purchase_receipts.id', 'purchase_receipts.reference', 'purchase_receipts.supplier_delivery_note', 'purchases.reference as purchase_reference', 'suppliers.name as supplier']);
            SearchQuery::match($receipts, ['purchase_receipts.reference', 'purchase_receipts.supplier_delivery_note', 'purchases.reference', 'suppliers.name'], $query);
            SearchQuery::rank($receipts, 'purchase_receipts.reference', $query);
            SearchQuery::rank($receipts, 'purchases.reference', $query);
            $results = $results->concat($receipts->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
                'GRNs / Receiving', $row->reference.' · '.$row->purchase_reference,
                trim(($row->supplier ?: 'Receiving').($row->supplier_delivery_note ? ' · Delivery: '.$row->supplier_delivery_note : '')),
                PurchaseReceiptResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-clipboard-document-check',
            )));
        }

        return $results;
    }
}
