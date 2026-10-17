<?php

namespace App\Filament\Resources\WarningTemplates\Pages;

use App\Filament\Resources\WarningTemplates\WarningTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarningTemplates extends ListRecords
{
    protected static string $resource = WarningTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => WarningTemplateResource::canCreate())];
    }
}
