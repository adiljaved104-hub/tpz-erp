<?php

namespace App\Filament\Resources\SafetClaims\Pages;

use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Filament\Resources\SafetClaims\Widgets\SafetClaimStats;
use Filament\Resources\Pages\ListRecords;

class ListSafetClaims extends ListRecords
{
    protected static string $resource = SafetClaimResource::class;

    protected function getHeaderWidgets(): array
    {
        return [SafetClaimStats::class];
    }
}
