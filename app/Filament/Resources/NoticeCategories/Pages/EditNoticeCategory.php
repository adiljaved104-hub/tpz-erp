<?php

namespace App\Filament\Resources\NoticeCategories\Pages;

use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrRecordAuthorization;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Auth\Access\AuthorizationException;

class EditNoticeCategory extends EditRecord
{
    protected static string $resource = NoticeCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $user = auth()->user();
        $authorization = app(HrRecordAuthorization::class);
        $scope = (string) ($data['communication_scope'] ?? '');

        throw_unless($user !== null
            && $authorization->canEditNoticeCategory($user, $this->record)
            && $authorization->canManageNoticeCategoryScope($user, $scope), AuthorizationException::class);

        return $data;
    }

    protected function afterSave(): void
    {
        app(ActivityLogger::class)->log('notice_category.updated', auth()->user(), $this->record, [
            'category_id' => $this->record->id,
            'changed_fields' => array_values(array_diff(array_keys($this->record->getChanges()), ['updated_at'])),
            'status' => $this->record->status,
        ]);
    }
}
