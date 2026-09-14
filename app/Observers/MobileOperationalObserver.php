<?php

namespace App\Observers;

use App\Contracts\EmployeePermissionOverrideResolver;
use App\Models\CustomerReturnStatusEvent;
use App\Models\OrderStatusEvent;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Models\WarrantyRepairStatusEvent;
use App\Notifications\MobileOperationalNotification;
use App\Services\Mobile\NotificationTarget;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

class MobileOperationalObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Model $model): void
    {
        $this->notify($model);
    }

    public function updated(Model $model): void
    {
        if ($model instanceof ResponsibilityAssignment && $model->wasChanged(['status', 'employee_id'])) {
            $this->notify($model);
        }
    }

    private function notify(Model $model): void
    {
        [$type,$id,$event] = match (true) {
            $model instanceof OrderStatusEvent => ['order', $model->order_id, 'order.status_changed'],
            $model instanceof WarrantyRepairStatusEvent => ['warranty_repair', $model->warranty_repair_id, 'warranty.status_changed'],
            $model instanceof CustomerReturnStatusEvent => ['customer_return', $model->customer_return_id, 'return.status_changed'],
            $model instanceof ResponsibilityAssignment => ['responsibility_assignment', $model->id, 'responsibility.changed'],
            default => [null, null, null],
        };
        if ($type === null) {
            return;
        }
        // Recipient eligibility and record scope are always resolved in Laravel.
        $payload = ['target_type' => $type, 'target_id' => $id, 'title' => str($event)->replace('.', ' ')->headline()->toString(), 'message' => 'An operational record has been updated.'];
        User::query()->whereHas('employee', fn ($q) => $q->where('status', true))->chunkById(100, function ($users) use ($payload, $event) {
            foreach ($users as $user) {
                try {
                    if (app(NotificationTarget::class)->resolve($user, $payload) !== null) {
                        $user->notify(new MobileOperationalNotification($event, $payload));
                    }
                } finally {
                    if ($user->employee !== null) {
                        app(EmployeePermissionOverrideResolver::class)->forgetEmployee($user->employee->id);
                    }
                }
            }
        });
    }
}
