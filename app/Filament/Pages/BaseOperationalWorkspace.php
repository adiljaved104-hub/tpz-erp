<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Pages\Page;
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
        ];
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
