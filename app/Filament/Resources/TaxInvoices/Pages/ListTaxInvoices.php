<?php

namespace App\Filament\Resources\TaxInvoices\Pages;

use App\Filament\Resources\TaxInvoices\TaxInvoiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

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

    public function getTabs(): array
    {
        $counts = TaxInvoiceResource::getEloquentQuery()
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw("COUNT(*) AS total_count, SUM(CASE WHEN status = 'void' THEN 1 ELSE 0 END) AS void_count")
            ->first();
        $all = (int) ($counts?->total_count ?? 0);
        $void = (int) ($counts?->void_count ?? 0);

        return [
            'active' => Tab::make('Active')
                ->badge($all - $void)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', '!=', 'void')),
            'void' => Tab::make('Void')
                ->badge($void)
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', 'void')),
            'all' => Tab::make('All')->badge($all),
        ];
    }
}
