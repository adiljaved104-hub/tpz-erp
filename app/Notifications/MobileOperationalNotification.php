<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class MobileOperationalNotification extends Notification
{
    public function __construct(public string $event, public array $payload) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->event;
    }

    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
