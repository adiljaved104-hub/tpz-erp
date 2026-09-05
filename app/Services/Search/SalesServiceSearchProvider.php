<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Enums\ComplaintPermission;
use App\Enums\CustomerReturnPermission;
use App\Enums\InvoicePermission;
use App\Enums\OrderPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\WarrantyRepairPermission;
use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Filament\Resources\InternalRepairs\InternalRepairResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource;
use App\Models\Complaint;
use App\Models\Order;
use App\Models\SafetClaim;
use App\Models\TaxInvoice;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\InvoiceAuthorization;
use App\Services\Authorization\OrderAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use App\Services\Returns\CustomerReturnReadService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesServiceSearchProvider implements GlobalSearchProvider
{
    public function search(User $user, string $query, int $limit): Collection
    {
        return collect()->concat($this->orders($user, $query, $limit))->concat($this->returns($user, $query, $limit))
            ->concat($this->claims($user, $query, $limit))->concat($this->warranties($user, $query, $limit))
            ->concat($this->complaints($user, $query, $limit))->concat($this->invoices($user, $query, $limit));
    }

    private function invoices(User $user, string $term, int $limit): Collection
    {
        $auth = app(InvoiceAuthorization::class);
        if (! $auth->allows($user, InvoicePermission::View)) {
            return collect();
        }
        $ids = $auth->scope(TaxInvoice::query()->select('tax_invoices.id'), $user);
        $query = DB::table('tax_invoices')->whereIn('id', $ids)->select(['id', 'invoice_number', 'order_reference', 'customer_name', 'invoice_date', 'status']);
        SearchQuery::match($query, ['invoice_number', 'order_reference', 'customer_name'], $term);
        SearchQuery::rank($query, 'invoice_number', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Invoices', $row->invoice_number, $row->customer_name.' · '.ucfirst($row->status), TaxInvoiceResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-document-currency-dollar'));
    }

    private function orders(User $user, string $term, int $limit): Collection
    {
        if (! app(OrderAuthorization::class)->allows($user, OrderPermission::View)) {
            return collect();
        }
        $ids = app(OrderResponsibilityScopeService::class)->applyOrders(Order::query()->select('orders.id'), $user);
        $query = DB::table('orders')->leftJoin('marketplace_platforms', 'marketplace_platforms.id', '=', 'orders.marketplace_platform_id')
            ->whereIn('orders.id', $ids)->select(['orders.id', 'orders.reference', 'orders.external_order_number', 'orders.status', 'marketplace_platforms.name as platform']);
        SearchQuery::match($query, ['orders.reference', 'orders.external_order_number'], $term);
        SearchQuery::rank($query, 'orders.reference', $term);
        SearchQuery::rank($query, 'orders.external_order_number', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Orders', $row->reference.($row->platform ? ' · '.$row->platform : ''),
            ($row->external_order_number ? 'External: '.$row->external_order_number.' · ' : '').ucfirst($row->status), OrderResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-shopping-bag'));
    }

    private function returns(User $user, string $term, int $limit): Collection
    {
        if (! app(CustomerReturnAuthorization::class)->allows($user, CustomerReturnPermission::View)) {
            return collect();
        }
        $ids = app(CustomerReturnReadService::class)->query($user)->select('customer_returns.id');
        $query = DB::table('customer_returns')->leftJoin('marketplace_platforms', 'marketplace_platforms.id', '=', 'customer_returns.marketplace_platform_id')
            ->whereIn('customer_returns.id', $ids)->select(['customer_returns.id', 'customer_returns.reference', 'customer_returns.status', 'marketplace_platforms.name as platform']);
        SearchQuery::match($query, ['customer_returns.reference'], $term);
        SearchQuery::rank($query, 'customer_returns.reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Returns', $row->reference.($row->platform ? ' · '.$row->platform : ''), ucfirst($row->status), CustomerReturnResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-arrow-uturn-left'));
    }

    private function claims(User $user, string $term, int $limit): Collection
    {
        $auth = app(SafetClaimAuthorization::class);
        if (! $auth->allows($user, SafetClaimPermission::View)) {
            return collect();
        }
        $ids = $auth->scopeQuery(SafetClaim::query()->select('safet_claims.id'), $user);
        $query = DB::table('safet_claims')->whereIn('id', $ids)->select(['id', 'reference', 'external_claim_reference', 'status']);
        SearchQuery::match($query, ['reference', 'external_claim_reference'], $term);
        SearchQuery::rank($query, 'reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Claims / Safe-T', $row->reference, ($row->external_claim_reference ? 'External: '.$row->external_claim_reference.' · ' : '').ucfirst($row->status), SafetClaimResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-document-check'));
    }

    private function warranties(User $user, string $term, int $limit): Collection
    {
        $auth = app(WarrantyRepairAuthorization::class);
        if (! $auth->allows($user, WarrantyRepairPermission::View)) {
            return collect();
        }
        $ids = $auth->scopeQuery(WarrantyRepair::query()->select('warranty_repairs.id'), $user);
        $query = DB::table('warranty_repairs')->join('products', 'products.id', '=', 'warranty_repairs.product_id')
            ->whereIn('warranty_repairs.id', $ids)->select(['warranty_repairs.id', 'warranty_repairs.reference', 'warranty_repairs.status', 'warranty_repairs.source', 'warranty_repairs.damaged_stock_event_id', 'products.sku', 'products.name']);
        SearchQuery::match($query, ['warranty_repairs.reference', 'warranty_repairs.external_service_reference', 'products.sku', 'products.name'], $term);
        SearchQuery::rank($query, 'warranty_repairs.reference', $term);
        SearchQuery::rank($query, 'products.sku', $term);
        SearchQuery::rank($query, 'products.name', $term);

        return $query->limit($limit)->get()->map(function ($row): GlobalSearchResult {
            $internal = $row->source === 'damaged_item' || $row->damaged_stock_event_id !== null;

            return new GlobalSearchResult($internal ? 'Internal Repairs' : 'Warranty', $row->reference.' · '.$row->sku, $row->name.' · '.ucfirst(str_replace('_', ' ', $row->status)),
                ($internal ? InternalRepairResource::class : WarrantyRepairResource::class)::getUrl('view', ['record' => $row->id]), 'heroicon-o-wrench-screwdriver');
        });
    }

    private function complaints(User $user, string $term, int $limit): Collection
    {
        $auth = app(ComplaintAuthorization::class);
        if (! $auth->allows($user, ComplaintPermission::View)) {
            return collect();
        }
        $ids = $auth->scopeQuery(Complaint::query()->select('complaints.id'), $user);
        $query = DB::table('complaints')->whereIn('id', $ids)->select(['id', 'reference', 'category', 'status']);
        SearchQuery::match($query, ['reference', 'category', 'description'], $term);
        SearchQuery::rank($query, 'reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Complaints', $row->reference.' · '.$row->category, ucfirst($row->status), ComplaintResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-chat-bubble-left-ellipsis'));
    }
}
