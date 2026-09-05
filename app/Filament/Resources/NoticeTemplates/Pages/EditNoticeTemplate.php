<?php

namespace App\Filament\Resources\NoticeTemplates\Pages;

use App\Filament\Resources\NoticeTemplates\NoticeTemplateResource;
use App\Services\ActivityLogger;
use Filament\Resources\Pages\EditRecord;

class EditNoticeTemplate extends EditRecord
{
    protected static string $resource = NoticeTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('notice_template.updated', auth()->user(), $this->record, [
            'template_id' => $this->record->id,
            'category_id' => $this->record->notice_category_id,
            'changed_fields' => array_values(array_diff(array_keys($this->record->getChanges()), ['updated_at'])),
            'status' => $this->record->status,
        ]);
    }
}
