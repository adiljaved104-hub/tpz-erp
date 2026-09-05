<?php

namespace App\Filament\Pages;

use App\Enums\WebSalesPermission;
use App\Models\User;
use App\Services\Authorization\WebSalesAuthorization;
use App\Services\Orders\WebSalesDashboardService;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class WebSalesDashboard extends Page
{
    protected string $view = 'filament.pages.web-sales-dashboard';

    protected static ?string $slug = 'web-sales/dashboard';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBarSquare;

    protected static string|\UnitEnum|null $navigationGroup = 'Web Sales';

    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?int $navigationSort = 2;

    #[Url]
    public string $period = 'today';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(WebSalesAuthorization::class)->allows($user, WebSalesPermission::View);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        [$from, $to] = app(WebSalesDashboardService::class)->range('today');
        $this->from = $this->from ?: $from->toDateString();
        $this->to = $this->to ?: $to->toDateString();
    }

    public function setPeriod(string $period): void
    {
        abort_unless(in_array($period, ['today', 'yesterday', 'week', 'month', 'custom'], true), 422);
        $this->period = $period;
        if ($period !== 'custom') {
            [$from, $to] = app(WebSalesDashboardService::class)->range($period);
            $this->from = $from->toDateString();
            $this->to = $to->toDateString();
        }
    }

    public function applyRange(): void
    {
        $this->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $this->period = 'custom';
    }

    public function getViewData(): array
    {
        $this->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $service = app(WebSalesDashboardService::class);
        [$from, $to] = $service->range('custom', $this->from, $this->to);

        return ['metrics' => $service->metrics(auth()->user(), $from, $to), 'rangeLabel' => $from->format('d M Y').' – '.$to->format('d M Y')];
    }
}
