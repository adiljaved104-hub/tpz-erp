<?php

namespace App\Enums;

enum ProductPermission: string
{
    case View = 'product.view';
    case Create = 'product.create';
    case Update = 'product.update';
    case ViewSellingPrice = 'product.view_selling_price';
    case EditSellingPrice = 'product.edit_selling_price';
    case ViewCostPrice = 'product.view_cost_price';
    case EditCostPrice = 'product.edit_cost_price';
    case Activate = 'product.activate';
    case Deactivate = 'product.deactivate';
    case Discontinue = 'product.discontinue';
    case Reactivate = 'product.reactivate';
    case Export = 'product.export';
}
