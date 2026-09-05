<?php

namespace App\Enums;

enum ComponentType: string
{
    case Ram = 'ram';
    case Ssd = 'ssd';
    case Battery = 'battery';
    case WifiCard = 'wifi_card';
    case GpuModule = 'gpu_module';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Ram => 'RAM',
            self::Ssd => 'SSD',
            self::Battery => 'Battery',
            self::WifiCard => 'Wi-Fi Card',
            self::GpuModule => 'GPU Module',
            self::Other => 'Other',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
