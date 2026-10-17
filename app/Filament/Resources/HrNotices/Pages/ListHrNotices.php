<?php

namespace App\Filament\Resources\HrNotices\Pages;

use App\Enums\HrCommunicationType;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHrNotices extends ListRecords
{
    protected static string $resource = HrNoticeResource::class;

    protected function getHeaderActions(): array
    {
        $resource = static::$resource;

        return [
            CreateAction::make()
                ->label('Publish '.($resource::communicationType() === HrCommunicationType::Announcement ? 'Announcement' : 'Official Notice'))
                ->visible(fn (): bool => $resource::canCreate()),
        ];
    }
}
