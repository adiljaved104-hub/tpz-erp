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
        if ($product->isDirty('created_by_user_id')) {
            throw new RuntimeException('The original Product creator is immutable.');
        }
    }

    public function deleting(Product $product): never
    {
        throw new ProductHardDeletionProhibitedException('Products cannot be hard-deleted.');
    }
}
