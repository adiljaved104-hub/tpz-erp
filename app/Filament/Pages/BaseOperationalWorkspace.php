<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Navigation\NavigationPreferenceService;
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
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedRecentRecords(): Collection
    {
        return collect(static::recentResources())
            ->filter(fn (string $resource): bool => $resource::canViewAny()
                && array_key_exists('view', $resource::getPages()))
            ->flatMap(function (string $resource): array {
                // Resource queries apply the existing employee and responsibility scope.
                return $resource::getEloquentQuery()
                    ->latest()
                    ->limit(4)
                    ->get()
                    ->filter(fn ($record): bool => $resource::canView($record))
                    ->map(fn ($record): array => [
                        'reference' => (string) ($record->reference ?? ('Record #'.$record->getKey())),
                        'url' => $resource::getUrl('view', ['record' => $record]),
                        'date' => $record->created_at?->format('d M Y') ?? '—',
                    ])
                    ->all();
            })
            ->take(6)
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private static function authorizedMetrics(): Collection
    {
        return collect(static::metricResources())
            ->filter(fn (array $item): bool => $item['class']::canViewAny())
            ->map(fn (array $item): array => [
                'label' => $item['label'],
                // Never query an unrestricted model. The Resource query carries
                // the same responsibility and access scope as its Filament table.
                'value' => $item['class']::getEloquentQuery()->count(),
                'icon' => $item['icon'],
                'url' => $item['class']::getUrl(),
            ])
            ->values();
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
