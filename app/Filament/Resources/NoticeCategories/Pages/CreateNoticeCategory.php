<?php

namespace App\Filament\Resources\NoticeCategories\Pages;

use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateNoticeCategory extends CreateRecord
{
    protected static string $resource = NoticeCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('notice_category.created', auth()->user(), $this->record, [
            'category_id' => $this->record->id,
            'status' => $this->record->status,
        ]);
    }
}
