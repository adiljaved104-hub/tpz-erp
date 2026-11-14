<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Finance\AccountingChartOfAccounts;
use App\Filament\Pages\Finance\AccountingGeneralLedger;
use App\Filament\Pages\Finance\AccountingHome;
use App\Filament\Pages\Finance\AccountingJournals;
use App\Filament\Pages\Finance\AccountingPeriods;
use App\Filament\Pages\Finance\AccountingTrialBalance;
use App\Filament\Pages\Finance\PakistanOfficeFinanceDashboard;
use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Support\Icons\Heroicon;

class FinanceWorkspace extends BaseOperationalWorkspace
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Finance';

    protected static ?string $navigationLabel = 'Finance & Accounting Workspace';

    protected static ?string $slug = 'finance-workspace';

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Finance & Accounting Workspace',
            'eyebrow' => 'Financial operations',
            'description' => 'Accounting, journals, expenses and financial reports within existing financial Access Control.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
                [
                    'title' => 'General accounting',
                    'description' => 'Existing AED book, journal, ledger and period workflows',
                    'links' => [
                        ['label' => 'Accounting Home', 'class' => AccountingHome::class, 'icon' => 'heroicon-o-banknotes'],
                        ['label' => 'Chart of Accounts', 'class' => AccountingChartOfAccounts::class, 'icon' => 'heroicon-o-rectangle-stack'],
                        ['label' => 'General Journal', 'class' => AccountingJournals::class, 'icon' => 'heroicon-o-document-text'],
                        ['label' => 'General Ledger', 'class' => AccountingGeneralLedger::class, 'icon' => 'heroicon-o-book-open'],
                        ['label' => 'Trial Balance', 'class' => AccountingTrialBalance::class, 'icon' => 'heroicon-o-scale'],
                        ['label' => 'Accounting Periods', 'class' => AccountingPeriods::class, 'icon' => 'heroicon-o-calendar-days'],
                    ],
                ],
                [
                    'title' => 'Office finance',
                    'description' => 'Existing authorized financial records and reports',
                    'links' => [
                        ['label' => 'Expenses', 'class' => ExpenseResource::class, 'icon' => 'heroicon-o-receipt-percent'],
                        ['label' => 'Pakistan Office Finance', 'class' => PakistanOfficeFinanceDashboard::class, 'icon' => 'heroicon-o-building-office'],
                    ],
                ],
            ],
        ];
    }
}
