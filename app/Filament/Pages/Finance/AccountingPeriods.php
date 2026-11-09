<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\AccountingPeriod;
use App\Services\Accounting\AccountingLedgerService;
use Filament\Notifications\Notification;

class AccountingPeriods extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-periods';

    protected static ?string $slug = 'finance/accounting/periods';

    protected static ?string $navigationLabel = 'Accounting Periods';

    protected static ?int $navigationSort = 40;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ManagePeriods;

    public function enablePosting(int $id): void
    {
        app(AccountingLedgerService::class)->enablePosting(AccountingBook::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Posting enabled for accounting book')->send();
    }

    public function closePeriod(int $id): void
    {
        app(AccountingLedgerService::class)->closePeriod(AccountingPeriod::query()->findOrFail($id), $this->user());
        Notification::make()->success()->title('Accounting period closed')->send();
    }

    protected function getViewData(): array
    {
        return ['books' => AccountingBook::query()->with('periods')->get()];
    }
}
