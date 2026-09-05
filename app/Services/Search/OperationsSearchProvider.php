<?php

namespace App\Services\Search;

use App\Contracts\GlobalSearchProvider;
use App\DTOs\GlobalSearchResult;
use App\Enums\InventoryPermission;
use App\Enums\ResponsibilityPermission;
use App\Enums\StockTransferPermission;
use App\Enums\TaskPermission;
use App\Filament\Resources\InventoryReservations\InventoryReservationResource;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use App\Filament\Resources\StockTransfers\StockTransferResource;
use App\Filament\Resources\Tasks\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Authorization\StockTransferAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Responsibilities\ResponsibilityProductScopeService;
use App\Services\StockTransfers\StockTransferReadService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationsSearchProvider implements GlobalSearchProvider
{
    public function search(User $user, string $query, int $limit): Collection
    {
        return collect()->concat($this->tasks($user, $query, $limit))->concat($this->transfers($user, $query, $limit))
            ->concat($this->reservations($user, $query, $limit))->concat($this->responsibilities($user, $query, $limit));
    }

    private function tasks(User $user, string $term, int $limit): Collection
    {
        $authorization = app(TaskAuthorization::class);
        if (! $authorization->allows($user, TaskPermission::View)) {
            return collect();
        }
        $ids = $authorization->scopeQuery(Task::query()->select('tasks.id'), $user);
        $query = DB::table('tasks')->leftJoin('employees', 'employees.id', '=', 'tasks.assigned_employee_id')
            ->leftJoin('teams', 'teams.id', '=', 'tasks.assigned_team_id')->whereIn('tasks.id', $ids)
            ->select(['tasks.id', 'tasks.reference', 'tasks.title', 'tasks.status', 'tasks.priority', 'employees.name as employee', 'teams.name as team']);
        SearchQuery::match($query, ['tasks.reference', 'tasks.title'], $term);
        SearchQuery::rank($query, 'tasks.reference', $term);
        SearchQuery::rank($query, 'tasks.title', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Tasks', $row->reference.' · '.$row->title,
            ucfirst(str_replace('_', ' ', $row->status)).' · '.ucfirst($row->priority).' · '.($row->employee ?: ($row->team ? 'Team: '.$row->team : 'Unassigned')),
            TaskResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-clipboard-document-list'));
    }

    private function transfers(User $user, string $term, int $limit): Collection
    {
        if (! app(StockTransferAuthorization::class)->allows($user, StockTransferPermission::View)) {
            return collect();
        }
        $ids = app(StockTransferReadService::class)->query($user)->select('stock_transfers.id');
        $query = DB::table('stock_transfers')->join('warehouses as source', 'source.id', '=', 'stock_transfers.source_warehouse_id')
            ->join('warehouses as destination', 'destination.id', '=', 'stock_transfers.destination_warehouse_id')->whereIn('stock_transfers.id', $ids)
            ->select(['stock_transfers.id', 'stock_transfers.reference', 'stock_transfers.status', 'source.name as source', 'destination.name as destination']);
        SearchQuery::match($query, ['stock_transfers.reference', 'source.name', 'destination.name'], $term);
        SearchQuery::rank($query, 'stock_transfers.reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Stock Transfers', $row->reference, $row->source.' → '.$row->destination.' · '.ucfirst($row->status), StockTransferResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-arrows-right-left'));
    }

    private function reservations(User $user, string $term, int $limit): Collection
    {
        if (! app(InventoryAuthorization::class)->allows($user, InventoryPermission::View)) {
            return collect();
        }
        $query = DB::table('inventory_reservations')->join('products', 'products.id', '=', 'inventory_reservations.product_id')
            ->whereIn('inventory_reservations.product_inventory_id', app(ResponsibilityProductScopeService::class)->inventoryIds($user))
            ->select(['inventory_reservations.id', 'inventory_reservations.reference', 'inventory_reservations.status', 'inventory_reservations.quantity', 'products.sku', 'products.name']);
        SearchQuery::match($query, ['inventory_reservations.reference', 'products.sku', 'products.name'], $term);
        SearchQuery::rank($query, 'inventory_reservations.reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Reservations', $row->reference.' · '.$row->sku, $row->name.' · Qty '.$row->quantity.' · '.ucfirst($row->status), InventoryReservationResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-lock-closed'));
    }

    private function responsibilities(User $user, string $term, int $limit): Collection
    {
        $authorization = app(ResponsibilityAuthorization::class);
        $query = DB::table('responsibility_assignments')->join('employees', 'employees.id', '=', 'responsibility_assignments.employee_id')
            ->select(['responsibility_assignments.id', 'responsibility_assignments.reference', 'responsibility_assignments.status', 'responsibility_assignments.assignment_mode', 'employees.name as employee']);
        if ($authorization->allows($user, ResponsibilityPermission::ViewAll)) {
            // Full authorized scope.
        } elseif ($authorization->allows($user, ResponsibilityPermission::ViewTeam) && $user->employee?->team_id !== null) {
            $query->where('employees.team_id', $user->employee->team_id);
        } elseif ($authorization->allows($user, ResponsibilityPermission::ViewOwn)) {
            $query->where('responsibility_assignments.employee_id', $user->employee->id);
        } else {
            return collect();
        }
        SearchQuery::match($query, ['responsibility_assignments.reference', 'employees.name'], $term);
        SearchQuery::rank($query, 'responsibility_assignments.reference', $term);

        return $query->limit($limit)->get()->map(fn ($row) => new GlobalSearchResult('Responsibility Assignments', $row->reference.' · '.$row->employee, ucfirst(str_replace('_', ' ', $row->assignment_mode)).' · '.ucfirst($row->status), ResponsibilityAssignmentResource::getUrl('view', ['record' => $row->id]), 'heroicon-o-user-group'));
    }
}
