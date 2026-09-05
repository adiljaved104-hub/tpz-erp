<?php

namespace App\Enums;

enum CatalogPermission: string
{
    case BrandView = 'catalog.brand.view';
    case BrandManage = 'catalog.brand.manage';
    case CategoryView = 'catalog.category.view';
    case CategoryManage = 'catalog.category.manage';
}
