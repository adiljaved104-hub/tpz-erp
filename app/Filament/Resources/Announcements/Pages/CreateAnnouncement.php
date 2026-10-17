<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\HrNotices\Pages\CreateHrNotice;

class CreateAnnouncement extends CreateHrNotice
{
    protected static string $resource = AnnouncementResource::class;
}
