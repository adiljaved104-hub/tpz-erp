<?php

namespace App\Filament\Pages;

use App\Enums\EmployeeRole;
use App\Services\Marketplace\MarketplaceOperationsSummaryService;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

class MarketplaceOperations extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-signal';

    protected static string|UnitEnum|null $navigationGroup = 'Sales';

    protected static ?string $navigationLabel = 'Marketplace Operations';

    protected static ?string $title = 'Marketplace Operations';

    protected static ?int $navigationSort = 45;

    protected string $view = 'filament.pages.marketplace-operations';

    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->employee?->role, [EmployeeRole::Owner, EmployeeRole::Admin], true);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getViewData(): array
    {
        return app(MarketplaceOperationsSummaryService::class)->summary();
    }
}
