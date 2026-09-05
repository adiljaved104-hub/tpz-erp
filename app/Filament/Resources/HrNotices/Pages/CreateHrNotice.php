<?php

namespace App\Filament\Resources\HrNotices\Pages;

use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Services\Hr\HrNoticeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHrNotice extends CreateRecord
{
    protected static string $resource = HrNoticeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(HrNoticeService::class)->publish($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return HrNoticeResource::getUrl('view', ['record' => $this->record]);
    }
}
