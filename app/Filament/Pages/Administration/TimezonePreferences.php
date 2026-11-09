<?php

namespace App\Filament\Pages\Administration;

use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\UserTimezoneResolver;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class TimezonePreferences extends Page
{
    protected string $view = 'filament.pages.administration.timezone-preferences';

    protected static ?string $slug = 'administration/timezone-preferences';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Timezone Preferences';

    public ?string $displayTimezone = null;

    public bool $useDeviceTimezone = false;

    public string $effectiveTimezone = '';

    public static function canAccess(): bool
    {
        return auth()->user() instanceof User;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $user = auth()->user();
        $this->displayTimezone = $user->display_timezone;
        $this->useDeviceTimezone = (bool) $user->use_device_timezone;
        $this->effectiveTimezone = app(UserTimezoneResolver::class)->resolve($user);
    }

    public function save(UserTimezoneResolver $resolver, ActivityLogger $activity): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        $data = $this->validate([
            'displayTimezone' => ['nullable', 'string', 'timezone:all'],
            'useDeviceTimezone' => ['boolean'],
        ]);
        $before = ['display_timezone' => $user->display_timezone, 'use_device_timezone' => (bool) $user->use_device_timezone];
        $user->forceFill([
            'display_timezone' => $data['displayTimezone'] ?: null,
            'use_device_timezone' => (bool) $data['useDeviceTimezone'],
        ])->save();
        $this->effectiveTimezone = $resolver->resolve($user);
        $activity->log('user.timezone_preferences_updated', $user, $user, [
            'before' => $before,
            'after' => ['display_timezone' => $user->display_timezone, 'use_device_timezone' => (bool) $user->use_device_timezone],
            'effective_timezone' => $resolver->resolve($user),
        ]);
        Notification::make()->success()->title('Timezone preferences saved')->send();
    }
}
