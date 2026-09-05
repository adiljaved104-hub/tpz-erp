<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTaxInvoices extends ListRecords
{
    protected static string $resource = TaxInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New Invoice')
                ->icon('heroicon-o-plus')
                ->visible(fn (): bool => TaxInvoiceResource::canCreate()),
        ];
    }
}
