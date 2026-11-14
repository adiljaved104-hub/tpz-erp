<?php

namespace App\Filament\Pages;

use Filament\Support\Icons\Heroicon;

class ReturnsQcWorkspace extends BaseOperationalWorkspace
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUturnLeft;

    protected static string|\UnitEnum|null $navigationGroup = 'Returns & Service';

    protected static ?string $navigationLabel = 'Returns & QC Workspace';

    protected static ?string $slug = 'returns-qc-workspace';

    protected static function workspaceDefinition(): array
    {
        return [
            'title' => 'Returns & QC Workspace',
            'eyebrow' => 'Evidence & quality operations',
            'description' => 'Customer returns, inspections, claims and repairs using existing custody and approval workflows.',
            'icon' => 'heroicon-o-squares-2x2',
            'sections' => [
            [
                'title' => 'Returns & evidence',
                'description' => 'Review authorized returns and marketplace recovery workflows',
                'links' => [
                    ['label' => 'Customer Returns', 'class' => \App\Filament\Resources\CustomerReturns\CustomerReturnResource::class, 'icon' => 'heroicon-o-arrow-uturn-left'],
                    ['label' => 'Marketplace Return Removals', 'class' => \App\Filament\Resources\MarketplaceReturnRemovals\MarketplaceReturnRemovalResource::class, 'icon' => 'heroicon-o-truck'],
                    ['label' => 'Safe-T Claims', 'class' => \App\Filament\Resources\SafetClaims\SafetClaimResource::class, 'icon' => 'heroicon-o-shield-check'],
                ],
            ],
            [
                'title' => 'Quality & repair',
                'description' => 'Existing inspection and service modules',
                'links' => [
                    ['label' => 'QC Inspections', 'class' => \App\Filament\Resources\QcInspections\QcInspectionResource::class, 'icon' => 'heroicon-o-clipboard-document-check'],
                    ['label' => 'Internal Repairs', 'class' => \App\Filament\Resources\InternalRepairs\InternalRepairResource::class, 'icon' => 'heroicon-o-wrench-screwdriver'],
                    ['label' => 'Warranty Repairs', 'class' => \App\Filament\Resources\WarrantyRepairs\WarrantyRepairResource::class, 'icon' => 'heroicon-o-cog-6-tooth'],
                ],
            ],
            ],
        ];
    }
}
