<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Resources\Quotations\Widgets\QuotationStats;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Quotation')->icon('heroicon-o-plus')->visible(fn () => QuotationResource::canCreate())];
    }

    protected function getHeaderWidgets(): array
    {
        return [QuotationStats::class];
    }
}
