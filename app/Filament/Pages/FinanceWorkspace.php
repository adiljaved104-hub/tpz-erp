<?php

namespace App\Filament\Pages;

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
                    ['label' => 'Accounting Home', 'class' => \App\Filament\Pages\Finance\AccountingHome::class, 'icon' => 'heroicon-o-banknotes'],
                    ['label' => 'Chart of Accounts', 'class' => \App\Filament\Pages\Finance\AccountingChartOfAccounts::class, 'icon' => 'heroicon-o-rectangle-stack'],
                    ['label' => 'General Journal', 'class' => \App\Filament\Pages\Finance\AccountingJournals::class, 'icon' => 'heroicon-o-document-text'],
                    ['label' => 'General Ledger', 'class' => \App\Filament\Pages\Finance\AccountingGeneralLedger::class, 'icon' => 'heroicon-o-book-open'],
                    ['label' => 'Trial Balance', 'class' => \App\Filament\Pages\Finance\AccountingTrialBalance::class, 'icon' => 'heroicon-o-scale'],
                    ['label' => 'Accounting Periods', 'class' => \App\Filament\Pages\Finance\AccountingPeriods::class, 'icon' => 'heroicon-o-calendar-days'],
                ],
            ],
            [
                'title' => 'Office finance',
                'description' => 'Existing authorized financial records and reports',
                'links' => [
                    ['label' => 'Expenses', 'class' => \App\Filament\Resources\Expenses\ExpenseResource::class, 'icon' => 'heroicon-o-receipt-percent'],
                    ['label' => 'Pakistan Office Finance', 'class' => \App\Filament\Pages\Finance\PakistanOfficeFinanceDashboard::class, 'icon' => 'heroicon-o-building-office'],
                ],
            ],
            ],
        ];
    }
}
