<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Navigation\NavigationPreferenceService;
use App\Services\Dashboard\ErpDashboardService;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Collection;

/**
 * Owner-opt-in landing page for existing ERP modules.
 * The module/resource retains its own Access Control and data responsibility checks.
 */
abstract class BaseOperationalWorkspace extends Page
{
    protected string $view = 'filament.pages.operational-workspace';

    protected static ?int $navigationSort = 0;

    public string $activeModule = 'overview';

    public string $recordSearch = '';

    /** @return array{title: string, eyebrow: string, description: string, icon: string, sections: array<int, array{title: string, description: string, links: array<int, array{label: string, class: class-string, icon: string}>}>} */
    abstract protected static function workspaceDefinition(): array;

    /** @return array<int, array<string, mixed>> */
    protected static function metricResources(): array
    {
        return [];
    }

    protected static function recentResources(): array
    {
        return [];
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(NavigationPreferenceService::class)->workspaceModeEnabled($user)
            && static::authorizedSections()->isNotEmpty();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return [
            'workspace' => static::workspaceDefinition(),
            'sections' => static::authorizedSections(),
            'metrics' => static::authorizedMetrics(),
            'quickActions' => static::authorizedQuickActions(),
            'recentRecords' => static::authorizedRecentRecords(),
            'moduleTabs' => static::authorizedModuleTabs(),
            'selectedModuleData' => $this->selectedModuleData(),
            'workspaceAttention' => $this->activeModule === 'overview' ? static::workspaceAttention() : collect(),
        ];
    }

    /** @return Collection<int,array{label:string,count:int,url:string,priority:int}> */
    protected static function workspaceAttention(): Collection
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return collect();
        }

        // Reuse the Dashboard's permission-scoped operational alerts.
        // Do not recalculate counts or bypass module policies.
        $title = static::workspaceDefinition()['title'];
        $pattern = match (true) {
            str_contains($title, 'Purchasing') => '/purchas|deliver|receiv|sourc/i',
            str_contains($title, 'Returns') => '/claim|return|qc|inspect|repair|complaint|warranty|damaged/i',
            str_contains($title, 'Finance') => '/invoic|expense|payment|account/i',
            str_contains($title, 'Team') => '/task|leave|warning|notice|acknowledg/i',
            str_contains($title, 'Administration') => '/backup|security|configuration/i',
            default => '/^$/',
        };

        return app(ErpDashboardService::class)
            ->forUser($user, widgetKeys: ['attention'])['attention']
            ->filter(fn (array $item): bool => preg_match($pattern, $item['label']) === 1)
            ->take(8)->values();
    }

    /**
     * The unified navigation must not make previously authorized modules
     * disappear. Combine the workspace's curated modules with other modules
     * from the user's existing permission-filtered Filament sidebar.
     *
     * @return Collection<int, array{label:string,url:string,icon:string}>
     */
    protected static function authorizedModuleTabs(): Collection
    {
        $definition = static::workspaceDefinition();
        $curated = static::authorizedSections()
            ->flatMap(fn (array $section) => $section['links'])
            ->values();

        $title = $definition['title'];
        $group = match (true) {
            str_contains($title, 'Purchasing') => 'Purchasing',
            str_contains($title, 'Returns') => 'Returns & QC',
            str_contains($title, 'Finance') => 'Finance & Accounting',
            str_contains($title, 'Team') => 'Team & HR',
            str_contains($title, 'Administration') => 'Administration',
            default => 'Home',
        };
        $additional = app(NavigationPreferenceService::class)
            ->workspaceModuleLinks(auth()->user(), $group);

        return $curated->concat($additional)->unique('url')->values();
    }

    /**
     * Only the selected authorized Resource is queried. All record queries
     * retain the existing Filament resource policy and responsibility scope.
     * Pages/non-resource modules continue through their existing secure URL.
     *
     * @return array{total: int|null, records: Collection, label: string, url: string, icon: string}|null
     */
    private function selectedModuleData(): ?array
    {
        if ($this->activeModule === 'overview' || ! ctype_digit($this->activeModule)) {
            return null;
        }

        $tab = static::authorizedModuleTabs()->get((int) $this->activeModule);
        if (! is_array($tab)) {
            return null;
        }

        $data = [
            'label' => $tab['label'],
            'url' => $tab['url'],
            'icon' => $tab['icon'],
            'total' => null,
            'records' => collect(),
        ];
        $resource = $tab['class'] ?? null;
        if (! is_string($resource) || ! is_subclass_of($resource, Resource::class)
            || ! $resource::canViewAny()) {
            return $data;
        }

        $scoped = $resource::getEloquentQuery();
        $data['total'] = (clone $scoped)->count();
        $searchable = $this instanceof ReturnsQcWorkspace && in_array($resource, [
            \App\Filament\Resources\CustomerReturns\CustomerReturnResource::class,
            \App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource::class,
        ], true);
        // Apply filters to the resource's permission-scoped query only.
        if ($searchable && trim($this->recordSearch) !== '') {
            $needle = addcslashes(trim($this->recordSearch), '%_\\\\');
            $scoped->where(function ($query) use ($needle, $resource): void {
                $query->where('reference', 'like', '%'.$needle.'%');
                if ($resource === \App\Filament\Resources\CustomerReturns\CustomerReturnResource::class) {
                    $query->orWhereHas('displayItems', fn ($items) => $items
                        ->where('sku_snapshot', 'like', '%'.$needle.'%')
                        ->orWhere('product_name_snapshot', 'like', '%'.$needle.'%'));
                } else {
                    $query->orWhere('external_removal_reference', 'like', '%'.$needle.'%')
                        ->orWhereHas('displayItems.product', fn ($product) => $product
                            ->where('sku', 'like', '%'.$needle.'%')
                            ->orWhere('name', 'like', '%'.$needle.'%'));
                }
            });
        }
        $data['filteredTotal'] = (clone $scoped)->count();

        if (! array_key_exists('view', $resource::getPages())) {
            return $data;
        }

        $modelClass = $resource::getModel();
        $keyName = (new $modelClass())->getQualifiedKeyName();
        $data['records'] = (clone $scoped)
            ->orderByDesc($keyName)
            ->limit($searchable ? 20 : 8)
            ->get()
            ->filter(fn ($record): bool => $resource::canView($record))
            ->map(function ($record) use ($resource): array {
                $status = $record->status ?? null;
                if ($status instanceof \BackedEnum) {
                    $status = $status->value;
                }

                return [
                    'reference' => (string) ($record->reference ?? $record->sku ?? $record->name
                        ?? $record->title ?? ('Record #'.$record->getKey())),
                    'date' => $record->created_at?->format('d M Y') ?? '—',
                    'status' => is_string($status) ? str($status)->headline()->toString() : null,
                    'products' => $record instanceof \App\Models\CustomerReturn
                        ? $record->displayItems->map(fn ($item): string => trim(($item->sku_snapshot ?? '').' — '.($item->product_name_snapshot ?? '')))->filter()->implode(' / ')
                        : null,
                    'url' => $resource::getUrl('view', ['record' => $record]),
                ];
            })
            ->values();

        return $data;
    }

    public function selectModule(string $index): void
    {
        abort_unless(static::canAccess(), 403);
        $this->recordSearch = '';
        if ($index === 'overview') {
            $this->activeModule = 'overview';
            return;
        }

        abort_unless(ctype_digit($index)
            && static::authorizedModuleTabs()->has((int) $index), 403);
        $this->activeModule = $index;
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedRecentRecords(): Collection
    {
        // Each record comes from an authorized, responsibility-scoped
        // Resource. No fabricated activity or unrestricted model reads.
        $curated = static::authorizedSections()
            ->flatMap(fn (array $section) => $section['links'])
            ->pluck('class')
            ->filter(fn (string $class): bool => is_subclass_of($class, Resource::class));

        return collect(static::recentResources())
            ->concat($curated)
            ->unique()
            ->filter(fn (string $resource): bool => $resource::canViewAny()
                && array_key_exists('view', $resource::getPages()))
            ->take(3)
            ->flatMap(function (string $resource): array {
                return $resource::getEloquentQuery()
                    ->latest()
                    ->limit(3)
                    ->get()
                    ->filter(fn ($record): bool => $resource::canView($record))
                    ->map(function ($record) use ($resource): array {
                        $status = $record->status ?? null;
                        if ($status instanceof \BackedEnum) {
                            $status = method_exists($status, 'getLabel')
                                ? $status->getLabel() : str($status->value)->headline()->toString();
                        }

                        return [
                            'reference' => (string) ($record->reference ?? $record->name ?? ('Record #'.$record->getKey())),
                            'source' => $resource::getModelLabel(),
                            'status' => is_string($status) ? $status : null,
                            'url' => $resource::getUrl('view', ['record' => $record]),
                            'date' => $record->created_at?->format('d M Y') ?? '—',
                            'sort_key' => $record->created_at?->timestamp ?? 0,
                        ];
                    })
                    ->all();
            })
            ->sortByDesc('sort_key')
            ->take(8)
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedMetrics(): Collection
    {
        // Build the same compact operational KPI row across workspaces.
        // Use only resources from the already authorized workspace sections.
        // A Page is not a record count and must never be presented as one.
        $curated = static::authorizedSections()
            ->flatMap(fn (array $section) => $section['links'])
            ->filter(fn (array $link): bool => is_subclass_of($link['class'], Resource::class))
            ->map(fn (array $link): array => [
                'label' => $link['label'], 'class' => $link['class'], 'icon' => $link['icon'],
            ]);

        $resources = collect(static::metricResources())
            ->concat($curated)
            ->unique('class')
            ->filter(fn (array $item): bool => $item['class']::canViewAny())
            ->take(6);

        $metrics = $resources->map(fn (array $item): array => [
            'label' => $item['label'],
            // Resource Eloquent query retains its existing permission / owner scope.
            'value' => $item['class']::getEloquentQuery()->count(),
            'icon' => $item['icon'],
            'url' => $item['class']::getUrl(),
        ])->values();

        // Non-resource workspaces such as Administration may have no record
        // counters. Show a clearly labeled factual navigation count instead.
        if ($metrics->count() < 3) {
            $modules = static::authorizedModuleTabs();
            if ($modules->isNotEmpty()) {
                $metrics->push([
                    'label' => 'Available Modules',
                    'value' => $modules->count(),
                    'icon' => 'heroicon-o-squares-2x2',
                    'url' => $modules->first()['url'],
                ]);
            }
        }

        return $metrics;
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedQuickActions(): Collection
    {
        $definition = static::workspaceDefinition();

        return collect($definition['sections'])
            ->flatMap(fn (array $section): array => $section['links'])
            ->filter(function (array $link): bool {
                $class = $link['class'];

                return is_subclass_of($class, Resource::class)
                    && array_key_exists('create', $class::getPages())
                    && $class::canCreate();
            })
            ->take(3)
            ->map(fn (array $link): array => [
                'label' => 'Create '.$link['label'],
                'icon' => $link['icon'],
                'url' => $link['class']::getUrl('create'),
            ])
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedSections(): Collection
    {
        $definition = static::workspaceDefinition();

        return collect($definition['sections'])
            ->map(function (array $section): array {
                $links = collect($section['links'])
                    ->filter(function (array $link): bool {
                        $class = $link['class'];

                        // Page gates and Resource policy gates must be respected.
                        if (is_subclass_of($class, Page::class)) {
                            return $class::canAccess();
                        }

                        return method_exists($class, 'canViewAny') && $class::canViewAny();
                    })
                    ->map(fn (array $link): array => [
                        'label' => $link['label'],
                        'icon' => $link['icon'],
                        'class' => $link['class'],
                        'url' => $link['class']::getUrl(),
                    ])
                    ->values();

                return [
                    'title' => $section['title'],
                    'description' => $section['description'],
                    'links' => $links,
                ];
            })
            ->filter(fn (array $section): bool => $section['links']->isNotEmpty())
            ->values();
    }
}
