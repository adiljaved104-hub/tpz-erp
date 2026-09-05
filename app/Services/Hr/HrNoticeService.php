<?php

namespace App\Services\Hr;

use App\Enums\HrPermission;
use App\Enums\NoticeAudienceType;
use App\Models\Employee;
use App\Models\HrAcknowledgment;
use App\Models\HrNotice;
use App\Models\HrNoticeRecipient;
use App\Models\NoticeCategory;
use App\Models\NoticeTemplate;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Notifications\HrRecordNotificationDispatcher;
use App\Services\ReferenceSequenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrNoticeService
{
    public function __construct(
        private readonly HrRecordAuthorization $authorization,
        private readonly ReferenceSequenceService $references,
        private readonly ActivityLogger $activity,
        private readonly HrRecordNotificationDispatcher $notifications,
    ) {}

    /** @param array<string, mixed> $data */
    public function publish(array $data, User $actor): HrNotice
    {
        $validated = validator($data, [
            'audience_type' => ['required', Rule::enum(NoticeAudienceType::class)],
            'notice_category_id' => ['nullable', 'integer', 'exists:notice_categories,id'],
            'notice_template_id' => ['nullable', 'integer', 'exists:notice_templates,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', 'distinct', 'exists:employees,id'],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
            'priority' => ['required', Rule::in(['normal', 'important'])],
            'published_at' => ['required', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:published_at'],
            'acknowledgment_required' => ['required', 'boolean'],
        ])->validate();
        $audience = $validated['audience_type'] instanceof NoticeAudienceType
            ? $validated['audience_type']
            : NoticeAudienceType::from($validated['audience_type']);
        $employeeIds = array_map('intval', $validated['employee_ids'] ?? []);
        if (isset($validated['notice_category_id']) && ! NoticeCategory::query()->whereKey($validated['notice_category_id'])->where('status', true)->exists()) {
            throw ValidationException::withMessages(['notice_category_id' => 'Select an active Notice Category.']);
        }
        if (isset($validated['notice_template_id']) && ! NoticeTemplate::query()
            ->whereKey($validated['notice_template_id'])
            ->where('notice_category_id', $validated['notice_category_id'] ?? null)
            ->where('status', true)->exists()) {
            throw ValidationException::withMessages(['notice_template_id' => 'Select an active Template belonging to the selected Notice Category.']);
        }
        throw_unless($this->authorization->canPublishNotice($actor, $audience, $validated['team_id'] ?? null, $employeeIds), AuthorizationException::class);
        $recipients = $this->recipients($audience, $validated['team_id'] ?? null, $employeeIds);
        if ($recipients->isEmpty()) {
            throw ValidationException::withMessages(['audience_type' => 'The selected Notice audience has no active Employees with login accounts.']);
        }

        $reference = $this->references->nextHrNoticeReference((int) date('Y', strtotime($validated['published_at'])));
        $notice = DB::transaction(function () use ($validated, $audience, $recipients, $actor, $reference): HrNotice {
            $notice = HrNotice::query()->create([
                'reference' => $reference,
                'notice_category_id' => $validated['notice_category_id'] ?? null,
                'notice_template_id' => $validated['notice_template_id'] ?? null,
                'title' => trim($validated['title']),
                'content' => trim($validated['content']),
                'audience_type' => $audience,
                'team_id' => $audience === NoticeAudienceType::Team ? $validated['team_id'] : null,
                'priority' => $validated['priority'],
                'published_at' => $validated['published_at'],
                'expires_at' => $validated['expires_at'] ?? null,
                'acknowledgment_required' => $validated['acknowledgment_required'],
                'published_by_user_id' => $actor->id,
                'status' => 'active',
            ]);
            foreach ($recipients as $employee) {
                HrNoticeRecipient::query()->create(['hr_notice_id' => $notice->id, 'employee_id' => $employee->id, 'created_at' => now()]);
                HrAcknowledgment::query()->create(['hr_notice_id' => $notice->id, 'employee_id' => $employee->id]);
            }
            $this->activity->log('notice.published', $actor, $notice, [
                'notice_id' => $notice->id,
                'audience_type' => $audience->value,
                'recipient_count' => $recipients->count(),
                'acknowledgment_required' => $notice->acknowledgment_required,
            ]);

            return $notice;
        });
        $this->notifications->noticePublished($notice);

        return $notice->load(['category', 'template', 'team', 'publishedBy', 'recipients.employee', 'acknowledgments']);
    }

    public function markRead(HrNotice $notice, User $actor): void
    {
        throw_unless($this->authorization->canViewNotice($actor, $notice), AuthorizationException::class);
        if ($actor->employee === null || ! HrNoticeRecipient::query()
            ->where('hr_notice_id', $notice->id)
            ->where('employee_id', $actor->employee->id)
            ->exists()) {
            return;
        }
        HrAcknowledgment::query()->where('hr_notice_id', $notice->id)
            ->where('employee_id', $actor->employee->id)->whereNull('read_at')->update(['read_at' => now(), 'updated_at' => now()]);
    }

    public function acknowledge(HrNotice $notice, User $actor): HrNotice
    {
        throw_unless($this->authorization->canViewNotice($actor, $notice), AuthorizationException::class);
        if (! $notice->acknowledgment_required || $actor->employee === null) {
            throw ValidationException::withMessages(['acknowledgment' => 'This Notice does not require acknowledgment.']);
        }
        throw_unless(HrNoticeRecipient::query()
            ->where('hr_notice_id', $notice->id)
            ->where('employee_id', $actor->employee->id)
            ->exists(), AuthorizationException::class);

        DB::transaction(function () use ($notice, $actor): void {
            $locked = HrNotice::query()->lockForUpdate()->findOrFail($notice->id);
            throw_unless(HrNoticeRecipient::query()
                ->where('hr_notice_id', $locked->id)
                ->where('employee_id', $actor->employee->id)
                ->lockForUpdate()
                ->exists(), AuthorizationException::class);
            $acknowledgment = HrAcknowledgment::query()->where('hr_notice_id', $locked->id)
                ->where('employee_id', $actor->employee->id)->lockForUpdate()->firstOrFail();
            if ($acknowledgment->acknowledged_at !== null) {
                throw ValidationException::withMessages(['acknowledgment' => 'This Notice is already acknowledged.']);
            }
            $snapshot = json_encode([
                'reference' => $locked->reference,
                'title' => $locked->title,
                'content' => $locked->content,
                'priority' => $locked->priority,
                'published_at' => $locked->published_at->toJSON(),
                'expires_at' => $locked->expires_at?->toJSON(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $acknowledgment->forceFill([
                'read_at' => $acknowledgment->read_at ?? now(),
                'acknowledged_at' => now(),
                'acknowledged_by_user_id' => $actor->id,
                'content_snapshot' => $snapshot,
                'content_hash' => hash('sha256', $snapshot),
            ])->save();
            $this->activity->log('notice.acknowledged', $actor, $locked, [
                'notice_id' => $locked->id, 'employee_id' => $actor->employee->id,
            ]);
        });

        return $notice->refresh()->load('acknowledgments');
    }

    public function archive(HrNotice $notice, User $actor): HrNotice
    {
        throw_unless($this->authorization->allows($actor, HrPermission::NoticeManage)
            && $this->authorization->canViewNotice($actor, $notice), AuthorizationException::class);

        return DB::transaction(function () use ($notice, $actor): HrNotice {
            $locked = HrNotice::query()->lockForUpdate()->findOrFail($notice->id);
            if ($locked->status === 'archived') {
                return $locked;
            }
            $locked->forceFill(['status' => 'archived', 'archived_at' => now(), 'archived_by_user_id' => $actor->id])->save();
            $this->activity->log('notice.archived', $actor, $locked, ['notice_id' => $locked->id]);

            return $locked;
        });
    }

    /** @param array<int, int> $employeeIds @return Collection<int, Employee> */
    private function recipients(NoticeAudienceType $audience, ?int $teamId, array $employeeIds): Collection
    {
        return Employee::query()->where('status', true)->whereNotNull('user_id')
            ->when($audience === NoticeAudienceType::Team, fn ($query) => $query->where('team_id', $teamId))
            ->when($audience === NoticeAudienceType::Selected, fn ($query) => $query->whereKey($employeeIds))
            ->orderBy('id')->get();
    }
}
