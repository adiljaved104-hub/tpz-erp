<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\User;
use App\Services\Authorization\AccountingAuthorization;
use Filament\Pages\Page;

abstract class BaseAccountingPage extends Page
{
    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewGeneralLedger;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(AccountingAuthorization::class)->allows($user, static::$requiredPermission);
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
}
