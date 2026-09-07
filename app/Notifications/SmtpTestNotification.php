<?php

namespace App\Notifications;

use App\Services\Branding\ApplicationBranding;
use App\Services\Notifications\EmailConfigurationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SmtpTestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return app(ApplicationBranding::class)->mail(new MailMessage, 'Email Delivery Test')
            ->greeting('Email delivery is configured')
            ->line('This is a test message from Tech Point Zone ERP.')
            ->line('No business record was created or changed.');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        return app(EmailConfigurationService::class)->apply(false);
    }
}
