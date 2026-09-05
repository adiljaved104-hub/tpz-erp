<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Enums\EmployeeRole;
use App\Enums\InventoryLocationPermission;
use App\Enums\PeoplePermission;
use App\Enums\ProductPermission;
use App\Enums\PurchasePermission;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Warehouses\WarehouseResource;
use App\Models\User;
use App\Services\Authorization\InventoryLocationAuthorization;
use App\Services\Authorization\PeopleAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\Authorization\PurchaseAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CatalogPeopleSearchProvider implements GlobalSearchProvider
{
    public function search(User $user, string $query, int $limit): Collection
    {
        return collect()
            ->concat($this->products($user, $query, $limit))
            ->concat($this->employees($user, $query, $limit))
            ->concat($this->suppliers($user, $query, $limit))
            ->concat($this->locations($user, $query, $limit));
    }

    private function products(User $user, string $term, int $limit): Collection
    {
        if (! app(ProductAuthorization::class)->allows($user, ProductPermission::View)) {
            return collect();
        }
        $query = DB::table('products')->where('products.inventory_item_type', 'product')->select(['products.id', 'products.sku', 'products.name', 'products.model', 'products.status']);
        app(ResponsibilityProductScopeService::class)->apply($query, 'products.id', $user);
        SearchQuery::match($query, ['products.sku', 'products.name', 'products.model'], $term);
        SearchQuery::rank($query, 'products.sku', $term);
        SearchQuery::rank($query, 'products.name', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
            'Products', $row->sku.' · '.$row->name, trim(($row->model ? $row->model.' · ' : '').ucfirst($row->status)),
            ProductResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-cube',
        ));
    }

    private function employees(User $user, string $term, int $limit): Collection
    {
        if (! app(PeopleAuthorization::class)->allows($user, PeoplePermission::EmployeeView)) {
            return collect();
        }
        $query = DB::table('employees')->leftJoin('teams', 'teams.id', '=', 'employees.team_id')
            ->select(['employees.id', 'employees.employee_id', 'employees.name', 'employees.role', 'employees.status', 'teams.name as team_name']);
        if ($user->employee?->role === EmployeeRole::Manager) {
            $query->where('employees.team_id', $user->employee->team_id);
        }
        SearchQuery::match($query, ['employees.employee_id', 'employees.name'], $term);
        SearchQuery::rank($query, 'employees.employee_id', $term);
        SearchQuery::rank($query, 'employees.name', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
            'Employees', $row->employee_id.' · '.$row->name,
            ucfirst($row->role).($row->team_name ? ' · '.$row->team_name : '').' · '.($row->status ? 'Active' : 'Inactive'),
            EmployeeResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-user',
        ));
    }

    private function suppliers(User $user, string $term, int $limit): Collection
    {
        if (! app(PurchaseAuthorization::class)->allows($user, PurchasePermission::SupplierView)) {
            return collect();
        }
        $query = DB::table('suppliers')->select(['id', 'name', 'contact_person', 'status']);
        SearchQuery::match($query, ['name', 'contact_person'], $term);
        SearchQuery::rank($query, 'name', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
            'Suppliers', $row->name, trim(($row->contact_person ?: 'Supplier').' · '.($row->status ? 'Active' : 'Inactive')),
            SupplierResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-building-storefront',
        ));
    }

    private function locations(User $user, string $term, int $limit): Collection
    {
        if (! app(InventoryLocationAuthorization::class)->allows($user, InventoryLocationPermission::View)) {
            return collect();
        }
        $query = DB::table('warehouses')->select(['id', 'code', 'name', 'location_type', 'status']);
        SearchQuery::match($query, ['code', 'name', 'fulfillment_tag'], $term);
        SearchQuery::rank($query, 'code', $term);
        SearchQuery::rank($query, 'name', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult(
            $row->location_type === 'company_warehouse' ? 'Warehouses' : 'Inventory Locations',
            $row->code.' · '.$row->name, str_replace('_', ' ', ucfirst($row->location_type)).' · '.($row->status ? 'Active' : 'Inactive'),
            WarehouseResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-map-pin',
        ));
    }
}
