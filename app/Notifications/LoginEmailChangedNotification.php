<?php

namespace App\Notifications;

use App\Services\Branding\ApplicationBranding;
use App\Services\Notifications\EmailConfigurationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginEmailChangedNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly bool $isPreviousAddress)
    {
        $this->afterCommit();
        $this->onQueue('notifications');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function via(object $notifiable): array
    {
        return app(EmailConfigurationService::class)->apply(false) ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(ApplicationBranding::class)->mail(new MailMessage, 'Login Email Changed')
            ->greeting('Login email updated')
            ->line($this->isPreviousAddress
                ? 'The ERP login email previously associated with your account has been changed.'
                : 'This email address is now the login email for your ERP account.')
            ->line('If you did not approve this change, contact your ERP administrator immediately.');
    }
}
