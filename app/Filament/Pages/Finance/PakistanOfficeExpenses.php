<?php

namespace App\Filament\Pages\Finance;

use Filament\Support\Icons\Heroicon;

class PakistanOfficeExpenses extends PakistanOfficeCashbook
{
    protected static ?string $slug = 'finance/pakistan-office/expenses';

    protected static ?string $navigationLabel = 'Pakistan Office Expenses';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 12;

    public function mount(): void
    {
        parent::mount();

        $this->expenseShortcut = true;
        $this->transactionType = 'expense';
        $this->typeFilter = 'expense';
    }
}
