<?php

namespace App\Observers;

use App\Exceptions\HardDeletionProhibitedException;
use App\Models\ProductCategory;

class ProductCategoryObserver
{
    public function deleting(ProductCategory $category): never
    {
        throw new HardDeletionProhibitedException('Product Categories cannot be hard-deleted. Set the Category inactive instead.');
    }
}
