<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class EditExpense extends EditRecord
{
    protected static string $resource = ExpenseResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Use this for UAE/Web Sales/Marketplace business expenses. Currency: AED.';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ExpenseService::class)->update($record, $data, auth()->user());
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Expense updated';
    }
}
