<?php

namespace App\Enums;

enum QualityControlDeviceType: string
{
    case WindowsLaptop = 'windows_laptop';
    case MacBook = 'macbook';
    case Ipad = 'ipad';
    case AndroidTablet = 'android_tablet';
    case OtherTablet = 'other_tablet';

    public function label(): string
    {
        return match ($this) {
            self::WindowsLaptop => 'Windows Laptop',
            self::MacBook => 'MacBook',
            self::Ipad => 'iPad',
            self::AndroidTablet => 'Android Tablet',
            self::OtherTablet => 'Other Tablet',
        };
    }
}
