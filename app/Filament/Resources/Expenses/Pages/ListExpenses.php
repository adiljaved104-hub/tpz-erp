<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'UAE / Web Sales / Marketplace operating expenses · Currency: AED';
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('New Business Expense')];
    }
}
