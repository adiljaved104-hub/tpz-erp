<?php

namespace App\Filament\Resources\NoticeCategories\Pages;

use App\Filament\Resources\NoticeCategories\NoticeCategoryResource;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrRecordAuthorization;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;

class CreateNoticeCategory extends CreateRecord
{
    protected static string $resource = NoticeCategoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        throw_unless($user !== null && app(HrRecordAuthorization::class)->canManageNoticeCategoryScope($user, (string) ($data['communication_scope'] ?? '')), AuthorizationException::class);

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
