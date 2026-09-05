<?php

namespace App\Filament\Pages\Purchasing;

use App\Enums\PurchasePermission;
use App\Models\User;
use App\Services\Authorization\PurchaseAuthorization;
use Filament\Pages\Page;

abstract class BasePurchaseReport extends Page
{
    protected string $view = 'filament.pages.purchasing-report';

    protected static string|\UnitEnum|null $navigationGroup = 'Purchasing';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(PurchaseAuthorization::class)->allows($user, PurchasePermission::View);
    }

    /** @return array<int, array<string, mixed>> */
    abstract public function rows(): array;

    /** @return array<string, array<string, mixed>> */
    public function filterDefinitions(): array
    {
        return [];
    }

    public function resetReportFilters(): void
    {
        foreach (array_keys($this->filterDefinitions()) as $property) {
            $this->{$property} = null;
        }
    }
}
