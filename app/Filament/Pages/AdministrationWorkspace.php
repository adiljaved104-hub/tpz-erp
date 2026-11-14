<?php

namespace App\Filament\Pages;

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
                    ['label' => 'Access Control', 'class' => \App\Filament\Pages\Administration\AccessControl::class, 'icon' => 'heroicon-o-key'],
                    ['label' => 'Login Security', 'class' => \App\Filament\Pages\Administration\LoginSecuritySettings::class, 'icon' => 'heroicon-o-lock-closed'],
                    ['label' => 'Backup Settings', 'class' => \App\Filament\Pages\Administration\BackupSettings::class, 'icon' => 'heroicon-o-circle-stack'],
                ],
            ],
            [
                'title' => 'Company settings',
                'description' => 'Existing ERP configuration without changing operational data',
                'links' => [
                    ['label' => 'Company Profile', 'class' => \App\Filament\Pages\Administration\CompanyProfile::class, 'icon' => 'heroicon-o-building-office'],
                    ['label' => 'Invoice Settings', 'class' => \App\Filament\Pages\Administration\InvoiceSettings::class, 'icon' => 'heroicon-o-document-text'],
                    ['label' => 'Order Settings', 'class' => \App\Filament\Pages\Administration\OrderSettings::class, 'icon' => 'heroicon-o-adjustments-horizontal'],
                    ['label' => 'Timezone Preferences', 'class' => \App\Filament\Pages\Administration\TimezonePreferences::class, 'icon' => 'heroicon-o-clock'],
                ],
            ],
            ],
        ];
    }
}
