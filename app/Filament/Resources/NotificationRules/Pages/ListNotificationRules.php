<?php

namespace App\Filament\Resources\NotificationRules\Pages;

use App\Filament\Resources\NotificationRules\NotificationRuleResource;
use App\Filament\Resources\NotificationRules\Widgets\NotificationRuleStats;
use Filament\Resources\Pages\ListRecords;

class ListNotificationRules extends ListRecords
{
    protected static string $resource = NotificationRuleResource::class;

    protected function getHeaderWidgets(): array
    {
        return [NotificationRuleStats::class];
    }
}
