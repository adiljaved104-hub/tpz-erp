<?php

namespace App\Filament\Resources\ProductBrands\Tables;

use App\Actions\Catalog\SetProductBrandStatus;
use App\DTOs\Catalog\ChangeCatalogStatusData;
use App\Models\ProductBrand;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductBrandsTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('products_count')->label('Products')->counts('products')->sortable(),
            IconColumn::make('status')->label('Active')->boolean()->sortable(),
            TextColumn::make('updated_at')->dateTime()->sortable(),
        ])->filters([TernaryFilter::make('status')->label('Active')])->recordActions([
            ViewAction::make(), EditAction::make(),
            Action::make('changeStatus')->label(fn (ProductBrand $record): string => $record->status ? 'Deactivate' : 'Activate')
                ->color(fn (ProductBrand $record): string => $record->status ? 'danger' : 'success')->requiresConfirmation()
                ->schema([Textarea::make('reason')->required()->maxLength(1000)])
                ->authorize(fn (ProductBrand $record): bool => auth()->user()->can('changeStatus', $record))
                ->action(fn (ProductBrand $record, array $data): ProductBrand => app(SetProductBrandStatus::class)->handle($record, new ChangeCatalogStatusData(! $record->status, $data['reason']), auth()->user())),
        ])->toolbarActions([]);
    }
}
