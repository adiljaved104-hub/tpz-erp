<?php

namespace App\Services\Notifications;

use App\Enums\HrPermission;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\LeaveDatabaseNotification;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HrScopeService;
use Illuminate\Support\Str;

class LeaveNotificationDispatcher
{
    public function __construct(
        private readonly HrAuthorization $authorization,
        private readonly HrScopeService $scope,
        private readonly NotificationRuleService $rules,
    ) {}

    public function submitted(LeaveRequest $request): void
    {
        $request->loadMissing('employee');
        Employee::query()->where('status', true)->whereNotNull('user_id')->with('user')->get()
            ->pluck('user')->filter(fn ($user): bool => $user instanceof User
                && ! $user->is($request->employee?->user)
                && $this->authorization->allows($user, HrPermission::LeaveApprove)
                && $this->scope->canApprove($user, $request))
            ->each(fn (User $user) => $this->send($user, 'leave.submitted', 'submitted', $request, 'Leave Request Submitted', "{$request->reference} is awaiting approval."));
    }

    public function decided(LeaveRequest $request): void
    {
        $request->loadMissing('employee.user');
        $recipient = $request->employee?->user;
        if (! $recipient instanceof User) {
            return;
        }
        $approved = $request->status === 'approved';
        $this->send($recipient, $approved ? 'leave.approved' : 'leave.rejected', $request->status, $request,
            $approved ? 'Leave Approved' : 'Leave Rejected',
            $approved ? "Your {$request->reference} request was approved." : "Your {$request->reference} request was rejected.");
    }

    private function send(User $recipient, string $type, string $event, LeaveRequest $request, string $title, string $message): void
    {
        if (! $this->rules->channelEnabled($type, 'in_app')) {
            return;
        }

        $id = $this->deterministicId("{$event}:{$request->id}:{$recipient->id}");
        if ($recipient->notifications()->whereKey($id)->exists()) {
            return;
        }
        $recipient->notify(new LeaveDatabaseNotification($id, $type, [
            'category' => 'leave', 'event' => $type, 'title' => $title, 'message' => Str::limit($message, 180),
            'target_type' => 'leave_request', 'target_id' => $request->id, 'leave_reference' => $request->reference, 'sound' => true,
        ]));
    }

    private function deterministicId(string $key): string
    {
        $hex = hash('sha256', 'tpz-erp-leave-notification:'.$key);
        $variant = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-'.$variant.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
