<?php

namespace App\Filament\Resources\WarningCategories\Pages;

use App\Filament\Resources\WarningCategories\WarningCategoryResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\EditRecord;

class EditWarningCategory extends EditRecord
{
    protected static string $resource = WarningCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('warning_category.updated', auth()->user(), $this->record, [
            'category_id' => $this->record->id,
            'changed_fields' => array_values(array_diff(array_keys($this->record->getChanges()), ['updated_at'])),
            'status' => $this->record->status,
        ]);
    }
}
