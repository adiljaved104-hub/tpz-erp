<?php

namespace App\Filament\Resources\WarningCategories\Pages;

use App\Filament\Resources\WarningCategories\WarningCategoryResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateWarningCategory extends CreateRecord
{
    protected static string $resource = WarningCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('warning_category.created', auth()->user(), $this->record, [
            'category_id' => $this->record->id,
            'status' => $this->record->status,
        ]);
    }
}
