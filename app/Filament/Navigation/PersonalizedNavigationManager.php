<?php

namespace App\Filament\Navigation;

use App\Models\User;
use App\Services\Navigation\NavigationPreferenceService;
use Filament\Navigation\NavigationManager;

class PersonalizedNavigationManager extends NavigationManager
{
    public function get(): array
    {
        $groups = parent::get();
        $preferences = app(NavigationPreferenceService::class);
        $user = auth()->user();

        if ($preferences->isBypassing() || ! $user instanceof User) {
            return $groups;
        }

        return $preferences->apply($user, $groups);
    }
}
