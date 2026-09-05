<?php

namespace App\Enums;

enum UpgradePermission: string
{
    case ManageHardwareProfiles = 'upgrade.manage_hardware_profiles';
    case ManageConfigurations = 'upgrade.manage_configurations';
    case ManageRecipes = 'upgrade.manage_recipes';
    case ManageSellingAddons = 'upgrade.manage_selling_addons';
    case ApproveRecoveryOverride = 'upgrade.approve_recovery_override';
}
