<?php

namespace App\Services\Responsibilities;

use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityCapacityStatus;
use App\Models\ProductInventory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResponsibilityReportService
{
    public function __construct(private readonly ResponsibilityCapacityService $capacity) {}

    public function summaries(): array
    {
        $active = DB::table('responsibility_assignments')->where('responsibility_assignments.status', ResponsibilityAssignmentStatus::Active->value);

        return [
            'by_employee' => (clone $active)
                ->join('employees as e', 'e.id', '=', 'responsibility_assignments.employee_id')
                ->select('e.employee_id', 'e.name', DB::raw('COUNT(*) as assignments'))
                ->groupBy('e.id', 'e.employee_id', 'e.name')->get()
                ->map(fn ($row): array => ['employee' => "{$row->employee_id} · {$row->name}", 'assignments' => $row->assignments]),
            'by_brand' => $this->brandCounts(),
            'by_category' => $this->scopeCounts('responsibility_assignment_categories', 'product_category_id'),
            'by_platform' => $this->scopeCounts('responsibility_assignment_platforms', 'marketplace_platform_id'),
            'by_product' => $this->productCounts(),
            'over_assigned' => $this->overAssigned(),
            'unassigned_products' => $this->unassignedProducts(),
            'history' => DB::table('responsibility_assignments as ra')
                ->join('employees as e', 'e.id', '=', 'ra.employee_id')
                ->where('ra.status', '!=', ResponsibilityAssignmentStatus::Active->value)
                ->latest('ra.ended_at')->limit(100)
                ->get(['ra.reference', 'e.employee_id', 'e.name', 'ra.assignment_mode', 'ra.status', 'ra.effective_at', 'ra.ended_at'])
                ->map(fn ($row): array => ['reference' => $row->reference, 'employee' => "{$row->employee_id} · {$row->name}", 'mode' => $row->assignment_mode, 'status' => $row->status, 'effective_at' => $row->effective_at, 'ended_at' => $row->ended_at]),
        ];
    }

    private function brandCounts(): Collection
    {
        $explicit = DB::table('responsibility_assignment_brands as scope')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->select('scope.assignment_id', 'scope.product_brand_id');
        $direct = DB::table('responsibility_assignment_products as scope')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->join('products as p', 'p.id', '=', 'scope.product_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->select('scope.assignment_id', 'p.brand_id as product_brand_id');
        $quantity = DB::table('inventory_responsibility_quantities as irq')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->join('product_inventories as pi', 'pi.id', '=', 'irq.product_inventory_id')
            ->join('products as p', 'p.id', '=', 'pi.product_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->select('irq.assignment_id', 'p.brand_id as product_brand_id');
        $category = DB::table('responsibility_assignment_categories as scope')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->join('products as p', 'p.category_id', '=', 'scope.product_category_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->whereNotExists(fn ($brand) => $brand->selectRaw('1')->from('responsibility_assignment_brands as category_brand_scope')->whereColumn('category_brand_scope.assignment_id', 'scope.assignment_id'))
            ->select('scope.assignment_id', 'p.brand_id as product_brand_id');

        return DB::query()->fromSub($explicit->union($direct)->union($quantity)->union($category), 'brand_scopes')
            ->join('product_brands as brands', 'brands.id', '=', 'brand_scopes.product_brand_id')
            ->select('brands.name as brand', DB::raw('COUNT(DISTINCT assignment_id) as assignments'))
            ->groupBy('brands.id', 'brands.name')->get();
    }

    private function productCounts(): Collection
    {
        $direct = DB::table('responsibility_assignment_products as scope')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->select('scope.assignment_id', 'scope.product_id');
        $quantity = DB::table('inventory_responsibility_quantities as irq')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->join('product_inventories as pi', 'pi.id', '=', 'irq.product_inventory_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->select('irq.assignment_id', 'pi.product_id');

        return DB::query()->fromSub($direct->union($quantity), 'product_scopes')
            ->join('products as products', 'products.id', '=', 'product_scopes.product_id')
            ->select('products.sku', 'products.name', DB::raw('COUNT(DISTINCT assignment_id) as assignments'))
            ->groupBy('products.id', 'products.sku', 'products.name')->get()
            ->map(fn ($row): array => ['product' => "{$row->sku} · {$row->name}", 'assignments' => $row->assignments]);
    }

    private function scopeCounts(string $table, string $column): Collection
    {
        $relatedTable = match ($column) {
            'marketplace_platform_id' => 'marketplace_platforms',
            'product_category_id' => 'product_categories',
            default => null,
        };

        $query = DB::table("{$table} as scope")
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value);

        if ($relatedTable !== null) {
            return $query->join("{$relatedTable} as related", 'related.id', '=', "scope.{$column}")
                ->select('related.name as '.($column === 'product_category_id' ? 'category' : 'platform'), DB::raw('COUNT(*) as assignments'))
                ->groupBy('related.id', 'related.name')->get();
        }

        return $query->select("scope.{$column}", DB::raw('COUNT(*) as assignments'))
            ->groupBy("scope.{$column}")->get();
    }

    private function overAssigned(): Collection
    {
        $inventoryIds = DB::table('inventory_responsibility_quantities as irq')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->distinct()->pluck('irq.product_inventory_id');
        $inventories = ProductInventory::query()->with(['product:id,sku,name', 'warehouse:id,name'])
            ->whereIn('id', $inventoryIds)->get()->keyBy('id');

        return $inventoryIds->map(function (int $inventoryId) use ($inventories): ?array {
            $summary = $this->capacity->summary($inventoryId);
            if ($summary['status'] !== ResponsibilityCapacityStatus::OverAssigned) {
                return null;
            }

            $inventory = $inventories->get($inventoryId);

            return [
                'sku' => $inventory->product->sku,
                'product' => $inventory->product->name,
                'location' => $inventory->warehouse->name,
                'original_allocated' => $summary['assigned'],
                'outstanding_allocated' => $summary['outstanding'],
                'sellable_quantity' => $summary['sellable'],
            ];
        })->filter()->values();
    }

    private function unassignedProducts(): Collection
    {
        $direct = DB::table('responsibility_assignment_products as rap')->join('responsibility_assignments as ra', 'ra.id', '=', 'rap.assignment_id')->where('ra.status', 'active')->select('rap.product_id');
        $quantity = DB::table('inventory_responsibility_quantities as irq')->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')->join('product_inventories as pi', 'pi.id', '=', 'irq.product_inventory_id')->where('ra.status', 'active')->select('pi.product_id');
        $dimensionProductIds = DB::table('products as dimension_product')
            ->whereExists(function ($assignment): void {
                $assignment->selectRaw('1')->from('responsibility_assignments as dimension_ra')
                    ->where('dimension_ra.status', ResponsibilityAssignmentStatus::Active->value)
                    ->where(function ($present): void {
                        $present->whereExists(fn ($brand) => $brand->selectRaw('1')->from('responsibility_assignment_brands as dimension_brand_present')->whereColumn('dimension_brand_present.assignment_id', 'dimension_ra.id'))
                            ->orWhereExists(fn ($category) => $category->selectRaw('1')->from('responsibility_assignment_categories as dimension_category_present')->whereColumn('dimension_category_present.assignment_id', 'dimension_ra.id'));
                    })->where(function ($brand): void {
                        $brand->whereNotExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as dimension_brand_none')->whereColumn('dimension_brand_none.assignment_id', 'dimension_ra.id'))
                            ->orWhereExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_brands as dimension_brand_match')->whereColumn('dimension_brand_match.assignment_id', 'dimension_ra.id')->whereColumn('dimension_brand_match.product_brand_id', 'dimension_product.brand_id'));
                    })->where(function ($category): void {
                        $category->whereNotExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as dimension_category_none')->whereColumn('dimension_category_none.assignment_id', 'dimension_ra.id'))
                            ->orWhereExists(fn ($scope) => $scope->selectRaw('1')->from('responsibility_assignment_categories as dimension_category_match')->whereColumn('dimension_category_match.assignment_id', 'dimension_ra.id')->whereColumn('dimension_category_match.product_category_id', 'dimension_product.category_id'));
                    });
            })->pluck('dimension_product.id');
        $assignedIds = $direct->union($quantity)->pluck('product_id')->merge($dimensionProductIds)->unique();

        return DB::table('products as p')->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')
            ->whereNotIn('p.id', $assignedIds)->get(['p.sku', 'p.name as product', 'b.name as brand']);
    }
}
