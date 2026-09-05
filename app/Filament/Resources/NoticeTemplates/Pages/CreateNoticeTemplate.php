<?php

namespace App\Filament\Resources\NoticeTemplates\Pages;

use App\Filament\Resources\NoticeTemplates\NoticeTemplateResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateNoticeTemplate extends CreateRecord
{
    protected static string $resource = NoticeTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by_user_id'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        app(ActivityLogger::class)->log('notice_template.created', auth()->user(), $this->record, [
            'template_id' => $this->record->id,
            'category_id' => $this->record->notice_category_id,
            'status' => $this->record->status,
        ]);
    }
}
