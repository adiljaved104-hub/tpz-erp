<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;

class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Use this for UAE/Web Sales/Marketplace business expenses. Currency: AED.';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(ExpenseService::class)->create($data, auth()->user());
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Expense recorded';
    }
}
