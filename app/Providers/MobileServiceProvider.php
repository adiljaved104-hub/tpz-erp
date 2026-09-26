<?php

namespace App\Providers;

use App\Models\CustomerReturnStatusEvent;
use App\Models\OrderStatusEvent;
use App\Models\ResponsibilityAssignment;
use App\Models\WarrantyRepairStatusEvent;
use App\Observers\MobileOperationalObserver;
use Illuminate\Support\ServiceProvider;

class MobileServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        foreach ([OrderStatusEvent::class, WarrantyRepairStatusEvent::class,
            CustomerReturnStatusEvent::class, ResponsibilityAssignment::class] as $model) {
            $model::observe(MobileOperationalObserver::class);
        }
    }
}
