<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Services\Accounting\AccountingLedgerService;

class AccountingGeneralLedger extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-general-ledger';

    protected static ?string $slug = 'finance/accounting/general-ledger';

    protected static ?string $navigationLabel = 'General Ledger';

    protected static ?int $navigationSort = 25;

    protected static AccountingPermission $requiredPermission = AccountingPermission::View;

    public string $bookId = '';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        parent::mount();
        $this->bookId = (string) (AccountingBook::query()->value('id') ?? '');
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    protected function getViewData(): array
    {
        $book = AccountingBook::query()->find((int) $this->bookId) ?? AccountingBook::query()->first();

        return ['book' => $book, 'lines' => $book ? app(AccountingLedgerService::class)->generalLedger($book, $this->from, $this->to) : collect()];
    }
}
