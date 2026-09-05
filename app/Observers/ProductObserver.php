<?php

namespace App\Observers;

use App\Exceptions\ProductHardDeletionProhibitedException;
use App\Models\Product;
use RuntimeException;

class ProductObserver
{
    public function updating(Product $product): void
    {
        if ($product->isDirty('sku')) {
            throw new RuntimeException('Product SKUs are immutable.');
        }
    }

    public function deleting(Product $product): never
    {
        throw new ProductHardDeletionProhibitedException('Products cannot be hard-deleted.');
    }
}
