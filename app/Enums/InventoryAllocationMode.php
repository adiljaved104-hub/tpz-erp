<?php

namespace App\Enums;

enum InventoryAllocationMode: string
{
    case MigrationShadow = 'migration_shadow';
    case Strict = 'strict';
}
