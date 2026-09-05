<?php

namespace App\Filament\Resources\NotificationRules\Widgets;

use App\Models\NotificationRule;
use App\Services\Notifications\EmailConfigurationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class NotificationRuleStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $globalEmail = app(EmailConfigurationService::class)->enabled();

        return [
            Stat::make('Active Rules', NotificationRule::query()->where('enabled', true)->count()),
            Stat::make('Email Enabled', NotificationRule::query()->where('enabled', true)->where('email_enabled', true)->count())
                ->description($globalEmail ? 'Global email delivery enabled' : 'Email delivery disabled in Email Settings')
                ->color($globalEmail ? 'success' : 'warning'),
            Stat::make('In-App Enabled', NotificationRule::query()->where('enabled', true)->where('in_app_enabled', true)->count()),
            Stat::make('Disabled Rules', NotificationRule::query()->where('enabled', false)->count())->color('gray'),
        ];
    }
}
