<?php

namespace App\Filament\Pages\Finance;

use App\Enums\OfficeFinancePermission;
use App\Models\User;
use App\Services\Authorization\OfficeFinanceAuthorization;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

abstract class BaseOfficeFinancePage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static OfficeFinancePermission $requiredPermission = OfficeFinancePermission::View;

    public function getSubheading(): string|Htmlable|null
    {
        return 'PKR office funding, expenses, employee loans and cashbook · Currency: PKR';
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(OfficeFinanceAuthorization::class)->allows($user, static::$requiredPermission);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }

    protected function allows(OfficeFinancePermission $permission): bool
    {
        return app(OfficeFinanceAuthorization::class)->allows($this->user(), $permission);
    }
}
