<?php

namespace App\Filament\Resources\MarketplacePlatforms\Tables;

use App\Actions\Responsibilities\SetMarketplacePlatformStatus;
use App\DTOs\Responsibilities\ChangeMarketplacePlatformStatusData;
use App\Filament\Resources\MarketplacePlatforms\Actions\ChangeMarketplacePlatformCodeAction;
use App\Models\MarketplacePlatform;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MarketplacePlatformsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('code')->searchable()->copyable(),
            TextColumn::make('return_handling_mode')->label('Return Handling')->badge()->placeholder('Not configured')->toggleable(),
            TextColumn::make('defaultReturnReceivingWarehouse.name')->label('Default Return Location')->placeholder('Not configured')->toggleable(),
            IconColumn::make('customer_return_claims_enabled')->label('Claims')->boolean()->toggleable(),
            TextColumn::make('claim_program_name')->label('Claim Program')->placeholder('—')->toggleable(),
            TextColumn::make('assignment_scopes_count')->label('Assignments')->counts('assignmentScopes')->sortable(),
            IconColumn::make('status')->label('Active')->boolean()->sortable(),
            TextColumn::make('updated_at')->dateTime('d M Y, h:i A')->sortable(),
        ])->filters([
            TernaryFilter::make('status')->label('Active'),
        ])->recordActions([
            ViewAction::make(),
            EditAction::make(),
            ChangeMarketplacePlatformCodeAction::make(),
            Action::make('changeStatus')
                ->label(fn (MarketplacePlatform $record): string => $record->status ? 'Deactivate' : 'Activate')
                ->color(fn (MarketplacePlatform $record): string => $record->status ? 'danger' : 'success')
                ->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->authorize(fn (MarketplacePlatform $record): bool => auth()->user()->can('changeStatus', $record))
                ->action(fn (MarketplacePlatform $record, array $data): MarketplacePlatform => app(SetMarketplacePlatformStatus::class)->handle($record, new ChangeMarketplacePlatformStatusData(! $record->status, $data['reason']), auth()->user())),
        ])->toolbarActions([]);
    }
}
