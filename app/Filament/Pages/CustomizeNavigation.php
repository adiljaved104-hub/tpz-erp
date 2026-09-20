<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class CustomizeNavigation extends Page
{
    public array $hiddenItems = [];

    protected string $view = 'filament.pages.customize-navigation';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Customize Navigation';

    protected static ?string $slug = 'customize-navigation';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->loadPreference();
    }

    public function toggleNavigationItem(string $key): void
    {
        /** @var User $user */
        $user = auth()->user();
        $groups = app(NavigationPreferenceService::class)->customizerGroups($user);
        $item = collect($groups)->flatMap(fn (array $group): array => $group['items'])->firstWhere('key', $key);
        abort_if($item === null || $item['protected'], 422);

        $this->hiddenItems = in_array($key, $this->hiddenItems, true)
            ? array_values(array_diff($this->hiddenItems, [$key]))
            : [...$this->hiddenItems, $key];
        app(NavigationPreferenceService::class)->saveHiddenItems($user, $this->hiddenItems);
        Notification::make()->success()->title('Navigation visibility saved')->send();
    }

    /** @param array<int, string> $keys */
    public function reorderNavigationGroups(array $keys): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(NavigationPreferenceService::class)->saveGroupOrder($user, array_values($keys));
        Notification::make()->success()->title('Navigation group order saved')->send();
    }

    public function resetNavigation(): void
    {
        /** @var User $user */
        $user = auth()->user();
        app(NavigationPreferenceService::class)->reset($user);
        $this->loadPreference();
        Notification::make()->success()->title('Navigation reset to default')->send();
    }

    protected function getViewData(): array
    {
        /** @var User $user */
        $user = auth()->user();

        return ['navigationGroups' => app(NavigationPreferenceService::class)->customizerGroups($user)];
    }

    private function loadPreference(): void
    {
        /** @var User $user */
        $user = auth()->user();
        $this->hiddenItems = collect(app(NavigationPreferenceService::class)->customizerGroups($user))
            ->flatMap(fn (array $group): array => $group['items'])
            ->where('hidden', true)
            ->pluck('key')->values()->all();
    }
}
