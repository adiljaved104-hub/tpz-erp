<?php

namespace App\Filament\Resources\Products\Tables;

use App\Actions\Products\ExportProducts;
use App\Actions\Products\SetProductStatus;
use App\DTOs\Products\ChangeProductStatusData;
use App\DTOs\Products\ProductExportData;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Enums\ProductStatus;
use App\Filament\Tables\Columns\PurchaseCostHistoryColumn;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sku')->label('SKU')->searchable()->sortable()->toggleable(),
                TextColumn::make('name')->label('Product')->searchable()->sortable()->toggleable(),
                TextColumn::make('brandRelation.name')->label('Brand')->badge()->searchable()->sortable()
                    ->formatStateUsing(fn ($state, Product $record): string => $state ?? $record->brand)->toggleable(),
                TextColumn::make('categoryRelation.name')->label('Category')->badge()->searchable()->sortable()
                    ->formatStateUsing(fn ($state, Product $record): string => $state ?? $record->category)->toggleable(),
                TextColumn::make('model')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('condition')
                    ->badge()
                    ->formatStateUsing(fn (ProductCondition $state): string => $state->label())
                    ->toggleable(),
                TextColumn::make('processor')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('ram')->label('RAM')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('storage')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('screen_size')->label('Screen')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('graphics')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('color')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('warranty')
                    ->formatStateUsing(fn (int $state): string => Product::warrantyLabel($state))
                    ->toggleable(),
                ...(self::allowed(ProductPermission::ViewSellingPrice) ? [
                    TextColumn::make('selling_price')
                        ->label('Selling Price')
                        ->money('AED')
                        ->sortable()
                        ->toggleable(),
                    TextColumn::make('price_readiness')
                        ->label('Price Readiness')
                        ->state(fn (Product $record): string => (float) $record->selling_price > 0 ? 'Ready' : 'Missing / Zero')
                        ->badge()
                        ->color(fn (string $state): string => $state === 'Ready' ? 'success' : 'warning')
                        ->toggleable(),
                ] : []),
                ...(self::allowed(ProductPermission::ViewCostPrice) ? [
                    TextColumn::make('cost_price')
                        ->label('Cost Price')
                        ->money('AED', decimalPlaces: 2)
                        ->sortable()
                        ->toggleable(),
                ] : []),
                ...(PurchaseCostHistoryColumn::allowed() ? [
                    PurchaseCostHistoryColumn::make(fn (Product $product): int => $product->id),
                ] : []),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (ProductStatus $state): string => $state->label())
                    ->color(fn (ProductStatus $state): string => $state->color())
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('condition')->options(self::conditionOptions()),
                SelectFilter::make('status')->options(self::statusOptions()),
                SelectFilter::make('brand_id')->label('Brand')->relationship('brandRelation', 'name')->searchable()->preload(),
                SelectFilter::make('category_id')->label('Category')->relationship('categoryRelation', 'name')->searchable()->preload(),
                ...(self::allowed(ProductPermission::ViewSellingPrice) ? [
                    Filter::make('missing_selling_price')
                        ->label('Active products with missing / zero selling price')
                        ->query(fn ($query) => $query
                            ->where('status', ProductStatus::Active->value)
                            ->where(fn ($price) => $price->whereNull('selling_price')->orWhere('selling_price', '<=', 0))),
                ] : []),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('changeStatus')
                    ->label('Change Status')
                    ->requiresConfirmation()
                    ->schema([
                        Select::make('status')
                            ->options(fn (Product $record): array => self::availableStatusOptions($record))
                            ->required(),
                        Textarea::make('reason')->required()->maxLength(1000),
                    ])
                    ->visible(fn (Product $record): bool => self::availableStatusOptions($record) !== [])
                    ->authorize(fn (Product $record): bool => self::availableStatusOptions($record) !== [])
                    ->action(fn (Product $record, array $data): Product => app(SetProductStatus::class)->handle(
                        $record,
                        new ChangeProductStatusData($data['status'], $data['reason']),
                        auth()->user(),
                    )),
            ])
            ->toolbarActions([
                Action::make('exportProducts')
                    ->label('Export Products')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->authorize(fn (): bool => self::allowed(ProductPermission::Export))
                    ->visible(fn (): bool => self::allowed(ProductPermission::Export))
                    ->schema([
                        TextInput::make('search')->maxLength(255),
                        Select::make('brand_id')->label('Brand')->options(fn (): array => ProductBrand::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        Select::make('category_id')->label('Category')->options(fn (): array => ProductCategory::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        Select::make('condition')->options(self::conditionOptions()),
                        Select::make('status')->options(self::statusOptions()),
                    ])
                    ->action(fn (array $data) => app(ExportProducts::class)->handle(new ProductExportData(
                        search: $data['search'] ?? null,
                        brandId: filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
                        categoryId: filled($data['category_id'] ?? null) ? (int) $data['category_id'] : null,
                        condition: $data['condition'] ?? null,
                        status: $data['status'] ?? null,
                    ), auth()->user())),
            ]);
    }

    /** @return array<string, string> */
    private static function conditionOptions(): array
    {
        return collect(ProductCondition::cases())
            ->mapWithKeys(fn (ProductCondition $condition): array => [$condition->value => $condition->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function statusOptions(): array
    {
        return collect(ProductStatus::cases())
            ->mapWithKeys(fn (ProductStatus $status): array => [$status->value => $status->label()])
            ->all();
    }

    /** @return array<string, string> */
    private static function availableStatusOptions(Product $product): array
    {
        $transitions = match ($product->status) {
            ProductStatus::Active => [
                ProductStatus::Inactive->value => ProductPermission::Deactivate,
                ProductStatus::Discontinued->value => ProductPermission::Discontinue,
            ],
            ProductStatus::Inactive => [
                ProductStatus::Active->value => ProductPermission::Activate,
                ProductStatus::Discontinued->value => ProductPermission::Discontinue,
            ],
            ProductStatus::Discontinued => [
                ProductStatus::Active->value => ProductPermission::Reactivate,
                ProductStatus::Inactive->value => ProductPermission::Deactivate,
            ],
        };

        return collect($transitions)
            ->filter(fn (ProductPermission $permission): bool => self::allowed($permission, $product))
            ->mapWithKeys(fn (ProductPermission $permission, string $status): array => [
                $status => ProductStatus::from($status)->label(),
            ])->all();
    }

    private static function allowed(ProductPermission $permission, ?Product $product = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ProductAuthorization::class)->allows($user, $permission, $product);
    }
}
