<?php

namespace App\Listeners;

use App\Jobs\SendMobilePush;
use App\Models\User;
use Illuminate\Notifications\Events\NotificationSent;

class QueueMobilePush
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel === 'database' && $event->notifiable instanceof User && config('mobile.push_enabled')) {
            SendMobilePush::dispatch($event->notifiable->id, (string) $event->notification->id)->afterCommit();
        }
    }
}
