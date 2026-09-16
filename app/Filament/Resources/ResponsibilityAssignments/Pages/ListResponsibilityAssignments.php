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
        $counts = ResponsibilityAssignmentResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw("COUNT(*) AS total_count, SUM(CASE WHEN status = 'active' AND ended_at IS NULL THEN 1 ELSE 0 END) AS active_count, SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_count")
            ->first();

        return [
            'active' => Tab::make('Active')
                ->badge((int) ($counts?->active_count ?? 0))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->active()),
            'inactive' => Tab::make('Inactive')
                ->badge((int) ($counts?->inactive_count ?? 0))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', ResponsibilityAssignmentStatus::Inactive->value)),
            'all' => Tab::make('All History')
                ->badge((int) ($counts?->total_count ?? 0)),
        ];
    }
}
