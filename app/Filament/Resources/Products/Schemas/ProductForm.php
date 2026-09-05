<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Actions\Catalog\CreateProductBrand;
use App\Actions\Catalog\CreateProductCategory;
use App\DTOs\Catalog\CreateCatalogItemData;
use App\Enums\CatalogPermission;
use App\Enums\ProductCondition;
use App\Enums\ProductPermission;
use App\Filament\Resources\Products\ProductResource;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
use App\Services\Authorization\CatalogAuthorization;
use App\Services\Authorization\ProductAuthorization;
use App\Services\ProductIntelligence\ProductDuplicateGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                // =========================
                // Basic Information
                // =========================

                TextInput::make('sku')
                    ->label('SKU')
                    ->disabled()
                    ->dehydrated(false)
                    ->placeholder('Auto Generated')
                    ->maxLength(100),

                TextInput::make('name')
                    ->label('Product Name')
                    ->required()
                    ->maxLength(255)
                    ->live(debounce: 500),

                Select::make('brand_id')
                    ->label('Brand')
                    ->options(fn (?Product $record): array => self::brandOptions($record))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(255)])
                    ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => self::catalogAllowed(CatalogPermission::BrandManage)))
                    ->createOptionUsing(fn (array $data): int => app(CreateProductBrand::class)->handle(new CreateCatalogItemData($data['name']), auth()->user())->id),

                Select::make('category_id')
                    ->label('Category')
                    ->options(fn (?Product $record): array => self::categoryOptions($record))
                    ->searchable()
                    ->preload()
                    ->required()
                    ->createOptionForm([TextInput::make('name')->required()->maxLength(255)])
                    ->createOptionAction(fn (Action $action): Action => $action->visible(fn (): bool => self::catalogAllowed(CatalogPermission::CategoryManage)))
                    ->createOptionUsing(fn (array $data): int => app(CreateProductCategory::class)->handle(new CreateCatalogItemData($data['name']), auth()->user())->id),

                TextInput::make('model')
                    ->label('Model Number')
                    ->live(debounce: 500),

                Select::make('condition')
                    ->options(self::conditionOptions())
                    ->default(ProductCondition::New->value)
                    ->required(),

                // =========================
                // Specifications
                // =========================

                TextInput::make('processor')
                    ->label('Processor')
                    ->live(debounce: 500),

                TextInput::make('ram')
                    ->label('RAM')
                    ->live(debounce: 500),

                TextInput::make('storage')
                    ->label('Storage')
                    ->live(debounce: 500),

                TextInput::make('screen_size')
                    ->label('Screen Size')
                    ->live(debounce: 500),

                TextInput::make('graphics')
                    ->label('Graphics')
                    ->live(debounce: 500),

                TextInput::make('color')
                    ->label('Color'),

                Placeholder::make('possible_duplicates')
                    ->label('Possible Duplicate Products')
                    ->content(fn (Get $get, ?Product $record): HtmlString => self::duplicateWarning($get, $record))
                    ->visible(fn (Get $get, ?Product $record): bool => self::duplicateCandidates($get, $record)->isNotEmpty())
                    ->columnSpanFull(),

                Textarea::make('duplicate_override_reason')
                    ->label('Reason to Continue With a Separate Product')
                    ->helperText('Required only when a likely duplicate is shown. The decision is recorded in the Activity Log.')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(fn (Get $get, ?Product $record): bool => self::duplicateCandidates($get, $record)->isNotEmpty())
                    ->visible(fn (Get $get, ?Product $record): bool => self::duplicateCandidates($get, $record)->isNotEmpty())
                    ->columnSpanFull(),

                // =========================
                // Business Information
                // =========================

                TextInput::make('warranty')
                    ->label('Warranty (months)')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(600)
                    ->default(12)
                    ->required(),

                TextInput::make('cost_price')
                    ->label('Cost Price')
                    ->numeric()
                    ->step(0.0001)
                    ->prefix('AED')
                    ->nullable()
                    ->visible(fn (?Product $record): bool => self::allowed(ProductPermission::ViewCostPrice, $record))
                    ->disabled(fn (?Product $record): bool => ! self::allowed(ProductPermission::EditCostPrice, $record))
                    ->dehydrated(fn (?Product $record): bool => self::allowed(ProductPermission::EditCostPrice, $record)),

                TextInput::make('selling_price')
                    ->label('Selling Price')
                    ->numeric()
                    ->step(0.01)
                    ->prefix('AED')
                    ->default(0)
                    ->required()
                    ->visible(fn (?Product $record): bool => self::allowed(ProductPermission::ViewSellingPrice, $record))
                    ->disabled(fn (?Product $record): bool => ! self::allowed(ProductPermission::EditSellingPrice, $record))
                    ->dehydrated(fn (?Product $record): bool => self::allowed(ProductPermission::EditSellingPrice, $record)),

                Textarea::make('description')
                    ->rows(5)
                    ->columnSpanFull(),

            ]);
    }

    /** @return array<string, string> */
    private static function conditionOptions(): array
    {
        return collect(ProductCondition::cases())
            ->mapWithKeys(fn (ProductCondition $condition): array => [$condition->value => $condition->label()])
            ->all();
    }

    private static function allowed(ProductPermission $permission, ?Product $product = null): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ProductAuthorization::class)->allows($user, $permission, $product);
    }

    /** @return array<int, string> */
    private static function brandOptions(?Product $record): array
    {
        return ProductBrand::query()->where(function ($query) use ($record): void {
            $query->where('status', true);
            if ($record?->brand_id !== null) {
                $query->orWhere('id', $record->brand_id);
            }
        })->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return array<int, string> */
    private static function categoryOptions(?Product $record): array
    {
        return ProductCategory::query()->where(function ($query) use ($record): void {
            $query->where('status', true);
            if ($record?->category_id !== null) {
                $query->orWhere('id', $record->category_id);
            }
        })->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function catalogAllowed(CatalogPermission $permission): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(CatalogAuthorization::class)->allows($user, $permission);
    }

    private static function duplicateCandidates(Get $get, ?Product $record)
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return collect();
        }

        return app(ProductDuplicateGuard::class)->candidates($user, [
            'name' => $get('name'),
            'brand_id' => $get('brand_id'),
            'model' => $get('model'),
            'processor' => $get('processor'),
            'ram' => $get('ram'),
            'storage' => $get('storage'),
            'screen_size' => $get('screen_size'),
            'graphics' => $get('graphics'),
        ], $record?->id);
    }

    private static function duplicateWarning(Get $get, ?Product $record): HtmlString
    {
        $items = self::duplicateCandidates($get, $record)->map(function ($result): string {
            $reasons = collect($result->reasons)->reject->isConflict()->take(3)->pluck('message')->map('e')->implode(' ');
            $url = e(ProductResource::getUrl('view', ['record' => $result->productId]));

            return '<li class="min-w-0 rounded-lg border border-warning-200 p-3 dark:border-warning-700">'
                .'<div class="break-words font-medium">'.e($result->sku.' · '.$result->name).'</div>'
                .'<div class="break-words text-sm text-gray-600 dark:text-gray-300">'.e($result->classification->label()).' · '.$reasons.'</div>'
                .'<a class="mt-2 inline-flex text-sm font-medium text-primary-600 hover:underline" href="'.$url.'" target="_blank" rel="noopener">Review / use existing Product</a>'
                .'</li>';
        })->implode('');

        return new HtmlString('<div class="min-w-0 rounded-xl bg-warning-50 p-4 text-sm dark:bg-warning-950/30"><p class="mb-3 font-medium">Review these existing Products before continuing. Nothing will be merged or changed automatically.</p><ul class="min-w-0 space-y-2">'.$items.'</ul></div>');
    }
}
