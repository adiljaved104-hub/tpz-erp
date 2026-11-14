<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Administration\AccessControl;
use App\Filament\Pages\Administration\BackupSettings;
use App\Filament\Pages\Administration\CompanyProfile;
use App\Filament\Pages\Administration\InvoiceSettings;
use App\Filament\Pages\Administration\LoginSecuritySettings;
use App\Filament\Pages\Administration\OrderSettings;
use App\Filament\Pages\Administration\TimezonePreferences;
use Filament\Support\Icons\Heroicon;

class AdministrationWorkspace extends BaseOperationalWorkspace
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Administration Workspace';

    protected static ?string $slug = 'administration-workspace';

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Administration Workspace',
            'eyebrow' => 'ERP control centre',
            'description' => 'Company configuration, governance and access management through existing authorized pages.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
                [
                    'title' => 'Security & governance',
                    'description' => 'Controlled access and system security',
                    'links' => [
                        ['label' => 'Access Control', 'class' => AccessControl::class, 'icon' => 'heroicon-o-key'],
                        ['label' => 'Login Security', 'class' => LoginSecuritySettings::class, 'icon' => 'heroicon-o-lock-closed'],
                        ['label' => 'Backup Settings', 'class' => BackupSettings::class, 'icon' => 'heroicon-o-circle-stack'],
                    ],
                ],
                [
                    'title' => 'Company settings',
                    'description' => 'Existing ERP configuration without changing operational data',
                    'links' => [
                        ['label' => 'Company Profile', 'class' => CompanyProfile::class, 'icon' => 'heroicon-o-building-office'],
                        ['label' => 'Invoice Settings', 'class' => InvoiceSettings::class, 'icon' => 'heroicon-o-document-text'],
                        ['label' => 'Order Settings', 'class' => OrderSettings::class, 'icon' => 'heroicon-o-adjustments-horizontal'],
                        ['label' => 'Timezone Preferences', 'class' => TimezonePreferences::class, 'icon' => 'heroicon-o-clock'],
                    ],
                ],
            ],
        ];
    }
}
