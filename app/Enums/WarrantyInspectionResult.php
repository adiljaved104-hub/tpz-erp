<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WarrantyInspectionResult: string implements HasLabel
{
    case NoFaultFound = 'no_fault_found';
    case NeedsRepair = 'needs_repair';
    case MissingAccessory = 'missing_accessory';
    case PhysicalDamage = 'physical_damage';
    case SoftwareSetupIssue = 'software_setup_issue';
    case Other = 'other';

    public function getLabel(): string
    {
        return str($this->value)->replace('_', ' ')->title()->toString();
    }
}
