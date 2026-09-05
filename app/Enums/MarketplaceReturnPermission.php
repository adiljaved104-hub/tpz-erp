<?php

namespace App\Enums;

enum MarketplaceReturnPermission: string
{
    case View = 'marketplace_return.view';
    case RequestRemoval = 'marketplace_return.request_removal';
    case DispatchToCompany = 'marketplace_return.dispatch_to_company';
    case ReceiveCompany = 'marketplace_return.receive_company';
}
