<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Services\Accounting\AccountingLedgerService;

class AccountingTrialBalance extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-trial-balance';

    protected static ?string $slug = 'finance/accounting/trial-balance';

    protected static ?string $navigationLabel = 'Trial Balance';

    protected static ?int $navigationSort = 30;

    protected static AccountingPermission $requiredPermission = AccountingPermission::ViewTrialBalance;

    public string $bookId = '';

    public string $asOfDate = '';

    public function mount(): void
    {
        parent::mount();
        $this->bookId = (string) (AccountingBook::query()->value('id') ?? '');
        $this->asOfDate = now()->toDateString();
    }

    protected function getViewData(): array
    {
        $book = AccountingBook::query()->find((int) $this->bookId) ?? AccountingBook::query()->first();

        return ['book' => $book, 'books' => AccountingBook::query()->orderBy('name')->get(), 'rows' => $book ? app(AccountingLedgerService::class)->trialBalance($book, $this->asOfDate) : collect()];
    }
}
