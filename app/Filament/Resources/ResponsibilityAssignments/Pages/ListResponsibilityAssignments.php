<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Pages;

use App\Enums\ResponsibilityAssignmentStatus;
use App\Filament\Resources\ResponsibilityAssignments\ResponsibilityAssignmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListResponsibilityAssignments extends ListRecords
{
    protected static string $resource = ResponsibilityAssignmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }

    public function getTabs(): array
    {
        $baseQuery = ResponsibilityAssignmentResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->reorder();

        $totalCount = (clone $baseQuery)->count();
        $activeCount = (clone $baseQuery)->active()->count();
        $inactiveCount = (clone $baseQuery)
            ->where('status', ResponsibilityAssignmentStatus::Inactive->value)
            ->count();

        return [
            'active' => Tab::make('Active')
                ->badge($activeCount)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->active()),
            'inactive' => Tab::make('Inactive')
                ->badge($inactiveCount)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ResponsibilityAssignmentStatus::Inactive->value)),
            'all' => Tab::make('All History')
                ->badge($totalCount),
        ];
    }
}
