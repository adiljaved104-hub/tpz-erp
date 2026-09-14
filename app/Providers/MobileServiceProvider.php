<?php

namespace App\Providers;

use App\Listeners\QueueMobilePush;
use App\Models\CustomerReturnStatusEvent;
use App\Models\OrderStatusEvent;
use App\Models\ResponsibilityAssignment;
use App\Models\WarrantyRepairStatusEvent;
use App\Observers\MobileOperationalObserver;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class MobileServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(NotificationSent::class, QueueMobilePush::class);
        foreach ([OrderStatusEvent::class, WarrantyRepairStatusEvent::class,
            CustomerReturnStatusEvent::class, ResponsibilityAssignment::class] as $model) {
            $model::observe(MobileOperationalObserver::class);
        }
    }
}
