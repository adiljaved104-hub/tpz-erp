<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplaintCategory: string implements HasLabel
{
    case ChargerMissing = 'charger_missing';
    case KeyboardMissing = 'keyboard_missing';
    case MouseMissing = 'mouse_missing';
    case PenStylusMissing = 'pen_stylus_missing';
    case WrongAccessory = 'wrong_accessory';
    case MissingAccessory = 'missing_accessory';
    case ProductNotWorking = 'product_not_working';
    case PhysicalDamage = 'physical_damage';
    case SoftwareSetupIssue = 'software_setup_issue';
    case PackagingIssue = 'packaging_issue';
    case WrongItem = 'wrong_item';
    case Other = 'other';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
