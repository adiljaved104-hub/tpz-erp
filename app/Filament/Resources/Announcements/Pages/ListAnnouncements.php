<?php

namespace App\Filament\Resources\Announcements\Pages;

use App\Filament\Resources\Announcements\AnnouncementResource;
use App\Filament\Resources\HrNotices\Pages\ListHrNotices;

class ListAnnouncements extends ListHrNotices
{
    protected static string $resource = AnnouncementResource::class;
}
