<?php

namespace App\Observers;

use App\Exceptions\HardDeletionProhibitedException;
use App\Models\ProductBrand;

class ProductBrandObserver
{
    public function deleting(ProductBrand $brand): never
    {
        throw new HardDeletionProhibitedException('Product Brands cannot be hard-deleted. Set the Brand inactive instead.');
    }
}
