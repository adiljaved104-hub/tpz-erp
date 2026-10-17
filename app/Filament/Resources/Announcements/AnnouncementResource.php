<?php

namespace App\Filament\Resources\Announcements;

use App\Enums\HrCommunicationType;
use App\Filament\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Resources\HrNotices\HrNoticeResource;

class AnnouncementResource extends HrNoticeResource
{
    protected static ?string $modelLabel = 'Announcement';

    protected static ?string $pluralModelLabel = 'Announcements';

    protected static ?string $navigationLabel = 'Announcements';

    protected static ?int $navigationSort = 5;

    public static function communicationType(): HrCommunicationType
    {
        return HrCommunicationType::Announcement;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
            'view' => ViewAnnouncement::route('/{record}'),
        ];
    }
}
