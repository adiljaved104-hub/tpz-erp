<?php

namespace App\Enums;

enum SafetClaimPermission: string
{
    case View = 'safet_claim.view';
    case File = 'safet_claim.file';
    case UpdateStatus = 'safet_claim.update_status';
    case Close = 'safet_claim.close';
    case Assign = 'safet_claim.assign';
    case ViewFinancial = 'claim.view_financial';
    case UpdateFinancial = 'claim.update_financial';
}
