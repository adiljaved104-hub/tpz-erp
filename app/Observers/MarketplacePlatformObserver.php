<?php

namespace App\Observers;

use App\Exceptions\HardDeletionProhibitedException;
use App\Exceptions\MarketplacePlatformCodeChangeException;
use App\Models\MarketplacePlatform;

class MarketplacePlatformObserver
{
    public function updating(MarketplacePlatform $platform): void
    {
        if ($platform->isDirty('code')) {
            throw new MarketplacePlatformCodeChangeException('Platform code can only be changed through the controlled Change Code action.');
        }
    }

    public function deleting(MarketplacePlatform $platform): never
    {
        throw new HardDeletionProhibitedException('Marketplace Platforms cannot be hard-deleted. Set the Platform inactive instead.');
    }
}
