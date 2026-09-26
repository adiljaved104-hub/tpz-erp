<?php

namespace App\Filament\Resources\Products\Pages;

use App\Enums\EmployeeRole;
use App\Enums\ProductPermission;
use App\Filament\Resources\Products\ProductResource;
use App\Models\User;
use App\Services\Authorization\ProductAuthorization;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importProducts')->label('Import Products')->icon('heroicon-o-arrow-up-tray')->url(ProductResource::getUrl('import'))
                ->visible(fn (): bool => ($user = auth()->user()) instanceof User
                    && in_array($user->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true)
                    && app(ProductAuthorization::class)->allows($user, ProductPermission::Create)),
            CreateAction::make()->label('New Product'),
        ];
    }
}
