<?php

namespace App\Filament\Resources\ResponsibilityAssignments;

use App\Filament\Resources\ResponsibilityAssignments\Pages\CreateResponsibilityAssignment;
use App\Filament\Resources\ResponsibilityAssignments\Pages\ListResponsibilityAssignments;
use App\Filament\Resources\ResponsibilityAssignments\Pages\ViewResponsibilityAssignment;
use App\Filament\Resources\ResponsibilityAssignments\Schemas\ResponsibilityAssignmentForm;
use App\Filament\Resources\ResponsibilityAssignments\Schemas\ResponsibilityAssignmentInfolist;
use App\Filament\Resources\ResponsibilityAssignments\Tables\ResponsibilityAssignmentsTable;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Responsibilities\ResponsibilityReadService;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ResponsibilityAssignmentResource extends Resource
{
    protected static ?string $model = ResponsibilityAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Responsibility Assignments';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User
            ? app(ResponsibilityReadService::class)->assignmentsFor($user)
            : parent::getEloquentQuery()->whereRaw('1 = 0');
    }

    public static function form(Schema $schema): Schema
    {
        return ResponsibilityAssignmentForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ResponsibilityAssignmentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ResponsibilityAssignmentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListResponsibilityAssignments::route('/'),
            'create' => CreateResponsibilityAssignment::route('/create'),
            'view' => ViewResponsibilityAssignment::route('/{record}'),
        ];
    }
}
