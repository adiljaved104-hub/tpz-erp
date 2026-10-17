<?php

namespace App\Filament\Resources\HrNotices\Pages;

use App\Enums\HrCommunicationType;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Services\Hr\HrNoticeService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateHrNotice extends CreateRecord
{
    protected static string $resource = HrNoticeResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $resource = static::$resource;
        $type = $resource::communicationType();

        return $type === HrCommunicationType::Announcement
            ? app(HrNoticeService::class)->publishAnnouncement($data, auth()->user())
            : app(HrNoticeService::class)->publish($data, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        $resource = static::$resource;

        return $resource::getUrl('view', ['record' => $this->record]);
    }
}
