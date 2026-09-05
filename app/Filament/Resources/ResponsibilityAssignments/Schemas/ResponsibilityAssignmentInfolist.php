<?php

namespace App\Filament\Resources\ResponsibilityAssignments\Schemas;

use App\Models\ResponsibilityAssignment;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ResponsibilityAssignmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Assignment')->schema([
                TextEntry::make('reference'),
                TextEntry::make('employee.name')->label('Employee'),
                TextEntry::make('team_name_at_assignment')->label('Team Snapshot')->placeholder('No Team'),
                TextEntry::make('assignment_mode')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('brandScope.brand.name')->label('Brand')->placeholder('—'),
                TextEntry::make('platformScope.platform.name')->label('Platform')->placeholder('—'),
                TextEntry::make('product_name')->label('Product')->state(fn (ResponsibilityAssignment $record): ?string => $record->productScope?->product?->name ?? $record->quantityScope?->inventory?->product?->name)->placeholder('—'),
                TextEntry::make('warehouse_name')->label('Warehouse')->state(fn (ResponsibilityAssignment $record): ?string => $record->quantityScope?->inventory?->warehouse?->name)->placeholder('—'),
                TextEntry::make('quantityScope.assigned_quantity')->label('Assigned Qty')->placeholder('—'),
                TextEntry::make('effective_at')->dateTime('d M Y, h:i A'),
                TextEntry::make('ended_at')->dateTime('d M Y, h:i A')->placeholder('Active'),
                TextEntry::make('assignedBy.name')->label('Assigned By'),
                TextEntry::make('endedBy.name')->label('Ended By')->placeholder('—'),
                TextEntry::make('predecessor.reference')->label('Previous Assignment')->placeholder('—'),
                TextEntry::make('reason')->columnSpanFull(),
                TextEntry::make('notes')->columnSpanFull()->placeholder('—'),
            ])->columns(3),
        ]);
    }
}
