<?php

namespace App\Filament\Pages\Inventory;

use App\Enums\ResponsibilityPermission;
use App\Models\User;
use App\Services\Authorization\ResponsibilityAuthorization;
use App\Services\Responsibilities\ResponsibilityReportService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class ResponsibilityReports extends Page
{
    protected string $view = 'filament.pages.inventory.responsibility-reports';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Inventory';

    protected static ?string $navigationLabel = 'Responsibility Reports';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ResponsibilityAuthorization::class)->allows($user, ResponsibilityPermission::ViewAll);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getViewData(): array
    {
        return ['reports' => app(ResponsibilityReportService::class)->summaries()];
    }
}
