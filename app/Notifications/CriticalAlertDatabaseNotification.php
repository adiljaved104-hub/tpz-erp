<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CriticalAlertDatabaseNotification extends Notification
{
    use Queueable;

    /** @param array<string, mixed> $payload */
    public function __construct(string $id, private readonly string $type, private readonly array $payload)
    {
        $this->id = $id;
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return $this->payload;
    }
}
