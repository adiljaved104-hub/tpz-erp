<?php

namespace App\Notifications;

use App\Enums\CustomerReturnPermission;
use App\Enums\InventoryPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\TaskPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\CustomerReturn;
use App\Models\EmployeeWarning;
use App\Models\HrNotice;
use App\Models\HrNoticeRecipient;
use App\Models\ProductInventory;
use App\Models\SafetClaim;
use App\Models\Task;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\HrRecordAuthorization;
use App\Services\Authorization\InventoryAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\TaskAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Branding\ApplicationBranding;
use App\Services\Notifications\EmailConfigurationService;
use App\Services\Notifications\NotificationRuleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CriticalAlertMailNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        private readonly string $subject,
        private readonly string $title,
        private readonly string $reference,
        private readonly string $reason,
        private readonly ?string $status = null,
        private readonly ?string $url = null,
        private readonly ?string $targetType = null,
        private readonly ?int $targetId = null,
        private readonly array $details = [],
        private readonly ?string $ruleEventKey = null,
    ) {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return app(EmailConfigurationService::class)->enabled() ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = app(ApplicationBranding::class)->mail(new MailMessage, $this->subject)
            ->greeting($this->title)
            ->line($this->reference)
            ->line($this->reason);

        if (filled($this->status)) {
            $mail->line('Status: '.$this->status);
        }

        foreach ($this->details as $label => $value) {
            if (filled($value)) {
                $mail->line($label.': '.$value);
            }
        }

        if (filled($this->url)) {
            $mail->action('Open in ERP', $this->url);
        }

        return $mail->line('This operational alert contains no financial information.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        if ($this->ruleEventKey !== null && ! app(NotificationRuleService::class)->channelEnabled($this->ruleEventKey, 'email')) {
            return false;
        }

        if (! app(EmailConfigurationService::class)->apply()) {
            return false;
        }

        if (! $notifiable instanceof User || $notifiable->employee?->status !== true) {
            return false;
        }

        return match ($this->targetType) {
            'task' => ($record = Task::query()->find($this->targetId)) instanceof Task
                && app(TaskAuthorization::class)->allows($notifiable, TaskPermission::View, $record),
            'warranty_repair' => ($record = WarrantyRepair::query()->find($this->targetId)) instanceof WarrantyRepair
                && app(WarrantyRepairAuthorization::class)->allows($notifiable, WarrantyRepairPermission::View, $record),
            'product_inventory' => ($record = ProductInventory::query()->find($this->targetId)) instanceof ProductInventory
                && app(InventoryAuthorization::class)->allows($notifiable, InventoryPermission::View, $record),
            'safet_claim' => ($record = SafetClaim::query()->find($this->targetId)) instanceof SafetClaim
                && app(SafetClaimAuthorization::class)->allows($notifiable, SafetClaimPermission::View, $record),
            'customer_return' => ($record = CustomerReturn::query()->find($this->targetId)) instanceof CustomerReturn
                && app(CustomerReturnAuthorization::class)->allows($notifiable, CustomerReturnPermission::View, $record),
            'employee_warning' => ($record = EmployeeWarning::query()->find($this->targetId)) instanceof EmployeeWarning
                && $record->employee_id === $notifiable->employee?->id
                && app(HrRecordAuthorization::class)->canViewWarning($notifiable, $record),
            'hr_notice' => ($record = HrNotice::query()->find($this->targetId)) instanceof HrNotice
                && $record->status === 'active'
                && $record->archived_at === null
                && HrNoticeRecipient::query()->where('hr_notice_id', $record->id)
                    ->where('employee_id', $notifiable->employee?->id)->exists()
                && app(HrRecordAuthorization::class)->canViewNotice($notifiable, $record),
            null => true,
            default => false,
        };
    }
}
