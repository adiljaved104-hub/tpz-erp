<?php

namespace App\Enums;

enum HrCommunicationType: string
{
    case OfficialNotice = 'official_notice';
    case Announcement = 'announcement';

    public function label(): string
    {
        return match ($this) {
            self::OfficialNotice => 'Official Notice',
            self::Announcement => 'Announcement',
        };
    }

    public function viewPermission(): HrPermission
    {
        return match ($this) {
            self::OfficialNotice => HrPermission::NoticeView,
            self::Announcement => HrPermission::AnnouncementView,
        };
    }

    public function publishPermission(): HrPermission
    {
        return match ($this) {
            self::OfficialNotice => HrPermission::NoticePublish,
            self::Announcement => HrPermission::AnnouncementPublish,
        };
    }

    public function managePermission(): HrPermission
    {
        return match ($this) {
            self::OfficialNotice => HrPermission::NoticeManage,
            self::Announcement => HrPermission::AnnouncementManage,
        };
    }
}
