<?php

namespace App\Enums;

enum MarketplaceObservationState: string
{
    case Yes = 'yes';
    case No = 'no';
    case Unknown = 'unknown';
}
