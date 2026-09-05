<?php

namespace App\Observers;

use App\Exceptions\ImmutableInventoryRecordException;
use App\Models\OpeningStockEntry;

class OpeningStockEntryObserver
{
    public function updating(OpeningStockEntry $entry): never
    {
        throw new ImmutableInventoryRecordException('Opening Stock entries cannot be updated.');
    }

    public function deleting(OpeningStockEntry $entry): never
    {
        throw new ImmutableInventoryRecordException('Opening Stock entries cannot be deleted.');
    }
}
