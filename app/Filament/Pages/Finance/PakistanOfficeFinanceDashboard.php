<?php

namespace App\Filament\Pages\Finance;

use App\Services\Finance\OfficeFinanceDashboardService;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class PakistanOfficeFinanceDashboard extends BaseOfficeFinancePage
{
    protected string $view = 'filament.pages.finance.pakistan-office-finance-dashboard';

    protected static ?string $slug = 'finance/pakistan-office';

    protected static ?string $navigationLabel = 'Pakistan Office Finance';

    protected static ?string $title = 'Dashboard';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 10;

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        parent::mount();
        [$from, $to] = app(OfficeFinanceDashboardService::class)->range('month');
        $this->from = $this->from ?: $from->toDateString();
        $this->to = $this->to ?: $to->toDateString();
    }

    public function setPeriod(string $period): void
    {
        [$from, $to] = app(OfficeFinanceDashboardService::class)->range($period, $this->from, $this->to);
        $this->period = $period;
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function applyRange(): void
    {
        $this->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        app(OfficeFinanceDashboardService::class)->range('custom', $this->from, $this->to);
        $this->period = 'custom';
    }

    public function getViewData(): array
    {
        [$from, $to] = app(OfficeFinanceDashboardService::class)->range('custom', $this->from, $this->to);

        return ['metrics' => app(OfficeFinanceDashboardService::class)->metrics($this->user(), $from, $to), 'rangeLabel' => $from->format('d M Y').' – '.$to->format('d M Y')];
    }
}
