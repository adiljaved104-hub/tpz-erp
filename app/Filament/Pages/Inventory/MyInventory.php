<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityReadService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class MyInventory extends Page
{
    protected string $view = 'filament.pages.inventory.my-inventory';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'My Inventory';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewOwn);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getViewData(): array
    {
        $service = app(ResponsibilityReadService::class);

        return [
            'inventoryRows' => $service->myInventory(auth()->user()),
            'platformResponsibilities' => $service->myPlatformResponsibilities(auth()->user()),
        ];
    }
}
