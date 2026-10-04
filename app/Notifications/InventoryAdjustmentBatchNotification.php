<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InventoryAdjustmentBatchNotification extends Notification
{
    use Queueable;

    /** @param array<int, string> $references */
    public function __construct(private readonly array $references, private readonly string $actorName) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'inventory_adjustment_batch';
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => 'Stock adjustments posted',
            'message' => $this->actorName.' posted '.count($this->references).' stock adjustment(s).',
            'references' => $this->references,
            'url' => '/admin/stock-adjustments',
        ];
    }
}
