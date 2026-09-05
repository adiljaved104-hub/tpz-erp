<?php

namespace App\Filament\Resources\NoticeTemplates\Pages;

use App\Filament\Resources\NoticeTemplates\NoticeTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListNoticeTemplates extends ListRecords
{
    protected static string $resource = NoticeTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->visible(fn (): bool => NoticeTemplateResource::canCreate())];
    }
}
