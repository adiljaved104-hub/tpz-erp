<?php

namespace App\Services\Notifications;

use App\Filament\Resources\EmployeeWarnings\EmployeeWarningResource;
use App\Filament\Resources\HrNotices\HrNoticeResource;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\User;
use App\Services\Authorization\HrRecordAuthorization;
use Illuminate\Support\Str;

class HrRecordNotificationDispatcher
{
    public function __construct(
        private readonly HrRecordAuthorization $authorization,
        private readonly CriticalAlertDispatcher $alerts,
    ) {}

    public function warningIssued(EmployeeWarning $warning): void
    {
        $warning->loadMissing(['employee.user', 'category']);
        $recipient = $warning->employee?->user;
        if (! $recipient instanceof User || ! $this->authorization->canViewWarning($recipient, $warning)) {
            return;
        }
        $this->alerts->send($recipient, 'warning.issued', 'warning:'.$warning->id, [
            'category' => 'hr', 'event' => 'warning.issued', 'title' => 'Warning Issued',
            'message' => $warning->reference.' — '.$warning->title,
            'reference' => $warning->reference,
            'target_type' => 'employee_warning', 'target_id' => $warning->id,
            'warning_reference' => $warning->reference,
            'acknowledgment_required' => $warning->acknowledgment_required,
            'sound' => true,
        ], '[ERP] Warning Issued — '.$warning->reference, EmployeeWarningResource::getUrl('view', ['record' => $warning]), [
            'Category' => $warning->category?->name ?? 'Uncategorized',
            'Issue date' => $warning->issued_date?->format('d M Y'),
            'Subject' => Str::limit($warning->title, 160),
            'Acknowledgment' => $warning->acknowledgment_required ? 'Required' : 'Not required',
        ]);
    }

    public function noticePublished(HrNotice $notice): void
    {
        if ($notice->status !== 'active' || $notice->archived_at !== null) {
            return;
        }

        $notice->loadMissing(['recipients.employee.user', 'category']);
        foreach ($notice->recipients as $recipientRow) {
            $recipient = $recipientRow->employee?->user;
            if (! $recipient instanceof User || ! $this->authorization->canViewNotice($recipient, $notice)) {
                continue;
            }
            $this->alerts->send($recipient, 'notice.published', 'notice:'.$notice->id, [
                'category' => 'hr', 'event' => 'notice.published', 'title' => $notice->priority === 'important' ? 'Important HR Notice' : 'HR Notice',
                'message' => $notice->reference.' — '.$notice->title,
                'reference' => $notice->reference,
                'target_type' => 'hr_notice', 'target_id' => $notice->id,
                'notice_reference' => $notice->reference,
                'acknowledgment_required' => $notice->acknowledgment_required,
                'sound' => $notice->priority === 'important',
            ], '[ERP] HR Notice — '.$notice->reference, HrNoticeResource::getUrl('view', ['record' => $notice]), [
                'Title' => Str::limit($notice->title, 160),
                'Category' => $notice->category?->name ?? 'Uncategorized',
                'Priority' => Str::headline($notice->priority),
                'Published' => $notice->published_at?->format('d M Y, h:i A'),
                'Summary' => Str::limit(strip_tags($notice->content), 240),
                'Acknowledgment' => $notice->acknowledgment_required ? 'Required' : 'Not required',
            ]);
        }
    }
}
