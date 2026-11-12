<?php

namespace App\Filament\Pages\Finance;

use App\Enums\AccountingPermission;
use App\Models\AccountingBook;
use App\Models\User;
use App\Services\Authorization\AccountingAuthorization;

class AccountingHome extends BaseAccountingPage
{
    protected string $view = 'filament.pages.finance.accounting-home';

    protected static ?string $slug = 'finance/accounting/home';

    protected static ?string $navigationLabel = 'Accounting Home';

    protected static ?int $navigationSort = 0;

    public string $bookId = '';

    public string $periodId = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $authorization = app(AccountingAuthorization::class);

        foreach ([
            AccountingPermission::ViewChart,
            AccountingPermission::ViewJournals,
            AccountingPermission::ViewPeriods,
            AccountingPermission::ViewGeneralLedger,
            AccountingPermission::ViewTrialBalance,
        ] as $permission) {
            if ($authorization->allows($user, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function mount(): void
    {
        parent::mount();
        $this->bookId = (string) (AccountingBook::query()->orderBy('name')->value('id') ?? '');
        $this->selectCurrentPeriod();
    }

    public function updatedBookId(): void
    {
        $this->selectCurrentPeriod();
    }

    protected function getViewData(): array
    {
        $user = $this->user();
        $authorization = app(AccountingAuthorization::class);
        $books = AccountingBook::query()->orderBy('name')->get();
        $book = $books->firstWhere('id', (int) $this->bookId) ?? $books->first();
        $periods = $book?->periods()->orderBy('starts_on')->get() ?? collect();
        $period = $periods->firstWhere('id', (int) $this->periodId) ?? null;
        $canViewJournals = $authorization->allows($user, AccountingPermission::ViewJournals);
        $canViewChart = $authorization->allows($user, AccountingPermission::ViewChart);
        $recentJournals = $canViewJournals && $book
            ? $book->journals()->with('reversal')->latest('created_at')->limit(5)->get()
            : collect();

        return [
            'book' => $book,
            'books' => $books,
            'period' => $period,
            'periods' => $periods,
            'recentJournals' => $recentJournals,
            'accountCount' => $canViewChart && $book ? $book->accounts()->count() : null,
            'canViewChart' => $canViewChart,
            'canViewJournals' => $canViewJournals,
            'canViewPeriods' => $authorization->allows($user, AccountingPermission::ViewPeriods),
            'canViewLedger' => $authorization->allows($user, AccountingPermission::ViewGeneralLedger),
            'canViewTrialBalance' => $authorization->allows($user, AccountingPermission::ViewTrialBalance),
            'navigationLinks' => collect([
                ['permission' => AccountingPermission::ViewChart, 'label' => 'Chart of Accounts', 'icon' => 'heroicon-o-rectangle-stack', 'url' => AccountingChartOfAccounts::getUrl()],
                ['permission' => AccountingPermission::ViewJournals, 'label' => 'General Journal', 'icon' => 'heroicon-o-document-text', 'url' => AccountingJournals::getUrl()],
                ['permission' => AccountingPermission::ViewGeneralLedger, 'label' => 'General Ledger', 'icon' => 'heroicon-o-book-open', 'url' => AccountingGeneralLedger::getUrl()],
                ['permission' => AccountingPermission::ViewTrialBalance, 'label' => 'Trial Balance', 'icon' => 'heroicon-o-scale', 'url' => AccountingTrialBalance::getUrl()],
                ['permission' => AccountingPermission::ViewPeriods, 'label' => 'Accounting Periods', 'icon' => 'heroicon-o-calendar-days', 'url' => AccountingPeriods::getUrl()],
            ])->filter(fn (array $link): bool => $authorization->allows($user, $link['permission']))
                ->map(fn (array $link): array => [...$link, 'active' => false])
                ->values(),
        ];
    }

    private function selectCurrentPeriod(): void
    {
        $book = AccountingBook::query()->find((int) $this->bookId);
        if (! $book) {
            $this->periodId = '';

            return;
        }

        $today = now()->toDateString();
        $period = $book->periods()->whereDate('starts_on', '<=', $today)->whereDate('ends_on', '>=', $today)->first()
            ?? $book->periods()->orderBy('starts_on')->first();

        $this->periodId = (string) ($period?->id ?? '');
    }
}
