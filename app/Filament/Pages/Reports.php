<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Reports\ReportCatalog;
use App\Services\Reports\ReportQueryService;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

class Reports extends Page
{
    protected string $view = 'filament.pages.reports';

    protected static ?string $slug = 'reports';

    protected static ?string $navigationLabel = 'Reports';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|\UnitEnum|null $navigationGroup = 'Analytics';

    protected static ?int $navigationSort = 1;

    #[Url]
    public string $report = '';

    #[Url]
    public string $period = 'month';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $status = '';

    #[Url]
    public string $employeeId = '';

    #[Url]
    public string $teamId = '';

    #[Url]
    public string $platformId = '';

    #[Url]
    public string $productId = '';

    public string $productSearch = '';

    #[Url]
    public string $brandId = '';

    #[Url]
    public string $warehouseId = '';

    #[Url]
    public string $supplierId = '';

    #[Url]
    public string $category = '';

    #[Url]
    public string $costCenter = '';

    #[Url]
    public string $channel = '';

    #[Url]
    public string $officeAccountId = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && app(ReportCatalog::class)->available($user) !== [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $today = CarbonImmutable::today(config('app.timezone'));
        $this->from = $this->from ?: $today->startOfMonth()->toDateString();
        $this->to = $this->to ?: $today->toDateString();
        $available = app(ReportCatalog::class)->available($this->user());
        if (! isset($available[$this->report])) {
            $this->report = (string) array_key_first($available);
        }
    }

    public function updatedReport(): void
    {
        app(ReportCatalog::class)->get($this->user(), $this->report);
        $this->reset(['status', 'employeeId', 'teamId', 'platformId', 'productId', 'productSearch', 'brandId', 'warehouseId', 'supplierId', 'category', 'costCenter', 'channel', 'officeAccountId']);
    }

    public function setPeriod(string $period): void
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(), $today->endOfWeek()],
            'month' => [$today->startOfMonth(), $today->endOfMonth()],
            default => [CarbonImmutable::parse($this->from), CarbonImmutable::parse($this->to)],
        };
        $this->period = in_array($period, ['today', 'week', 'month', 'custom'], true) ? $period : 'month';
        $this->from = $from->toDateString();
        $this->to = $to->toDateString();
    }

    public function applyFilters(): void
    {
        $this->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    public function exportUrl(string $format): string
    {
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);
        app(ReportCatalog::class)->get($this->user(), $this->report);

        return route('reports.export', ['report' => $this->report, 'format' => $format])
            .'?'.http_build_query(array_filter($this->filters(), fn (mixed $value): bool => filled($value)));
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $catalog = app(ReportCatalog::class);
        $definitions = $catalog->available($this->user());
        $definition = $catalog->get($this->user(), $this->report);
        $queries = app(ReportQueryService::class);

        return [
            'reports' => collect($definitions)->groupBy('group', preserveKeys: true),
            'definition' => $definition,
            'result' => $queries->run($this->user(), $this->report, $this->filters()),
            'options' => $queries->filterOptions(
                $this->user(),
                $definition['filters'],
                $this->productSearch,
                filled($this->productId) ? (int) $this->productId : null,
            ),
            'exportFormats' => collect($definition['formats'])->filter(
                fn (string $format): bool => $catalog->canExport($this->user(), $this->report, $format),
            )->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        return [
            'from' => $this->from, 'to' => $this->to, 'status' => $this->status,
            'employee_id' => $this->employeeId, 'team_id' => $this->teamId,
            'platform_id' => $this->platformId, 'product_id' => $this->productId,
            'brand_id' => $this->brandId, 'warehouse_id' => $this->warehouseId,
            'supplier_id' => $this->supplierId,
            'category' => $this->category, 'cost_center' => $this->costCenter,
            'channel' => $this->channel,
            'office_account_id' => $this->officeAccountId,
        ];
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
