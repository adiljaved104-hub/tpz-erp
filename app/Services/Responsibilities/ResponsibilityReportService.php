<?php

namespace App\Services\Responsibilities;

use App\Enums\ResponsibilityAssignmentStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResponsibilityReportService
{
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

        return DB::query()->fromSub($explicit->union($direct)->union($quantity), 'brand_scopes')
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
        $relatedTable = $column === 'marketplace_platform_id' ? 'marketplace_platforms' : null;

        $query = DB::table("{$table} as scope")
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'scope.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value);

        if ($relatedTable !== null) {
            return $query->join("{$relatedTable} as related", 'related.id', '=', "scope.{$column}")
                ->select('related.name as platform', DB::raw('COUNT(*) as assignments'))
                ->groupBy('related.id', 'related.name')->get();
        }

        return $query->select("scope.{$column}", DB::raw('COUNT(*) as assignments'))
            ->groupBy("scope.{$column}")->get();
    }

    private function overAssigned(): Collection
    {
        return DB::table('product_inventories as pi')
            ->join('inventory_responsibility_quantities as irq', 'irq.product_inventory_id', '=', 'pi.id')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->groupBy('pi.id', 'pi.product_id', 'pi.warehouse_id', 'pi.available_quantity', 'pi.reserved_quantity', 'p.sku', 'p.name', 'w.name')
            ->havingRaw('SUM(irq.assigned_quantity) > (pi.available_quantity - pi.reserved_quantity)')
            ->join('products as p', 'p.id', '=', 'pi.product_id')
            ->join('warehouses as w', 'w.id', '=', 'pi.warehouse_id')
            ->select('p.sku', 'p.name as product', 'w.name as location', DB::raw('SUM(irq.assigned_quantity) as assigned_quantity'), DB::raw('(pi.available_quantity - pi.reserved_quantity) as sellable_quantity'))
            ->get();
    }

    private function unassignedProducts(): Collection
    {
        $direct = DB::table('responsibility_assignment_products as rap')->join('responsibility_assignments as ra', 'ra.id', '=', 'rap.assignment_id')->where('ra.status', 'active')->select('rap.product_id');
        $quantity = DB::table('inventory_responsibility_quantities as irq')->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')->join('product_inventories as pi', 'pi.id', '=', 'irq.product_inventory_id')->where('ra.status', 'active')->select('pi.product_id');
        $brandProductIds = DB::table('responsibility_assignment_brands as rab')->join('responsibility_assignments as ra', 'ra.id', '=', 'rab.assignment_id')->join('products as p', 'p.brand_id', '=', 'rab.product_brand_id')->where('ra.status', 'active')->pluck('p.id');
        $assignedIds = $direct->union($quantity)->pluck('product_id')->merge($brandProductIds)->unique();

        return DB::table('products as p')->leftJoin('product_brands as b', 'b.id', '=', 'p.brand_id')
            ->whereNotIn('p.id', $assignedIds)->get(['p.sku', 'p.name as product', 'b.name as brand']);
    }
}
