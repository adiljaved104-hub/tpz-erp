<?php

namespace App\Filament\Resources\Quotations\Widgets;

use App\Enums\QuotationStatus;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class QuotationStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $q = QuotationResource::getEloquentQuery();

        return [
            Stat::make('Total Quotations', (clone $q)->count()), Stat::make('Draft', (clone $q)->where('status', QuotationStatus::Draft->value)->whereDate('valid_until', '>=', today())->count()),
            Stat::make('Sent', (clone $q)->where('status', QuotationStatus::Sent->value)->whereDate('valid_until', '>=', today())->count()), Stat::make('Accepted', (clone $q)->where('status', QuotationStatus::Accepted->value)->count()),
            Stat::make('Converted', (clone $q)->where('status', QuotationStatus::Converted->value)->count()), Stat::make('Rejected / Expired', (clone $q)->where(fn ($query) => $query->where('status', QuotationStatus::Rejected->value)->orWhere(fn ($expired) => $expired->effectivelyExpired()))->count()),
            Stat::make('Quotation Value', 'AED '.number_format((float) (clone $q)->sum('grand_total'), 2)),
        ];
    }
}
