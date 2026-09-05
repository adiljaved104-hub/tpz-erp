<?php

namespace App\Filament\Resources\ProductCategories\Tables;

use App\Actions\Catalog\SetProductCategoryStatus;
use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\Models\ProductCategory;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductCategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('products_count')->label('Products')->counts('products')->sortable(),
            IconColumn::make('status')->label('Active')->boolean()->sortable(), TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('status')->label('Active')])->recordActions([
            ViewAction::make(), EditAction::make(),
            Action::make('changeStatus')->label(fn (ProductCategory $record): string => $record->status ? 'Deactivate' : 'Activate')
                ->color(fn (ProductCategory $record): string => $record->status ? 'danger' : 'success')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->authorize(fn (ProductCategory $record): bool => auth()->user()->can('changeStatus', $record))
                ->action(fn (ProductCategory $record, array $data): ProductCategory => app(SetProductCategoryStatus::class)->handle($record, new ChangeCatalogStatusData(! $record->status, $data['reason']), auth()->user())),
        ])->toolbarActions([]);
    }
}
