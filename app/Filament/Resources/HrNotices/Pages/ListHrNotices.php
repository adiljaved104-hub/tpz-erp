<?php

namespace App\Filament\Resources\HrNotices\Pages;

use App\Filament\Resources\HrNotices\HrNoticeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListHrNotices extends ListRecords
{
    protected static string $resource = HrNoticeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Publish Notice')
                ->visible(fn (): bool => HrNoticeResource::canCreate()),
        ];
    }
}
