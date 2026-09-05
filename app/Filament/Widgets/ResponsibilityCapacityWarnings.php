<?php

namespace App\Filament\Widgets;

use App\Enums\ResponsibilityAssignmentStatus;
use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class ResponsibilityCapacityWarnings extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewAll);
    }

    protected function getStats(): array
    {
        $rows = DB::table('product_inventories as pi')
            ->join('inventory_responsibility_quantities as irq', 'irq.product_inventory_id', '=', 'pi.id')
            ->join('responsibility_assignments as ra', 'ra.id', '=', 'irq.assignment_id')
            ->where('ra.status', ResponsibilityAssignmentStatus::Active->value)
            ->groupBy('pi.id', 'pi.available_quantity', 'pi.reserved_quantity')
            ->selectRaw('SUM(irq.assigned_quantity) as assigned, (pi.available_quantity - pi.reserved_quantity) as sellable')->get();

        return [
            Stat::make('Over-assigned Products', $rows->filter(fn (object $row): bool => $row->assigned > $row->sellable)->count())->color('danger'),
            Stat::make('At Capacity', $rows->filter(fn (object $row): bool => $row->assigned === $row->sellable)->count())->color('warning'),
        ];
    }
}
