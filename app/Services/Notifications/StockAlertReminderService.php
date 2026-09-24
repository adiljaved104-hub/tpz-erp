<?php

namespace App\Services\Notifications;

use App\Enums\EmployeeRole;
use App\Enums\InventoryPermission;
use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\StockAlertIncident;
use App\Models\StockAlertIncidentRecipient;
use App\Models\User;
use App\Services\Authorization\InventoryAuthorization;

class StockAlertReminderService
{
    public function __construct(
        private readonly StockAlertIncidentService $incidents,
        private readonly CriticalAlertRecipientResolver $recipients,
        private readonly CriticalAlertDispatcher $alerts,
        private readonly InventoryAuthorization $inventoryAuthorization,
    ) {}

    /** @return array{reminders:int, escalations:int, resolved:int} */
    public function evaluate(): array
    {
        $counts = ['reminders' => 0, 'escalations' => 0, 'resolved' => 0];

        StockAlertIncident::query()
            ->whereNull('resolved_at')
            ->with(['inventory.product', 'inventory.warehouse', 'recipients.user.employee'])
            ->chunkById(100, function ($active) use (&$counts): void {
                foreach ($active as $incident) {
                    $current = $this->incidents->reconcile($incident->inventory);
                    if (! $current instanceof StockAlertIncident || $current->id !== $incident->id || $current->resolved_at !== null) {
                        $counts['resolved']++;

                        continue;
                    }

                    $current->load(['inventory.product', 'inventory.warehouse', 'recipients.user.employee']);
                    $counts['reminders'] += $this->sendDueReminders($current);
                    $counts['escalations'] += $this->escalateIfDue($current);
                }
            });

        return $counts;
    }

    private function sendDueReminders(StockAlertIncident $incident): int
    {
        $schedule = $incident->alert_type === StockAlertIncident::OUT_OF_STOCK
            ? config('mobile.stock_alerts.out_of_stock_reminder_hours', [2, 24, 48])
            : config('mobile.stock_alerts.low_stock_reminder_hours', [24]);
        $dueStep = collect($schedule)
            ->keys()
            ->filter(fn (int $index): bool => $incident->opened_at->addHours((int) $schedule[$index])->isPast())
            ->max();

        if (! is_int($dueStep)) {
            return 0;
        }

        $sent = 0;
        foreach ($incident->recipients->where('recipient_role', StockAlertIncidentRecipient::PRIMARY) as $recipient) {
            if ($recipient->acknowledged_at !== null || $recipient->reminder_step > $dueStep || ! $this->recipientStillAuthorized($recipient, $incident)) {
                continue;
            }

            $user = $recipient->user;
            $type = $this->incidents->eventType($incident);
            $eventKey = 'stock-alert:'.$incident->incident_key.':reminder:'.$dueStep;
            $label = $this->incidents->productLabel($incident);
            $title = $incident->alert_type === StockAlertIncident::OUT_OF_STOCK ? 'Out of Stock Reminder' : 'Low Stock Reminder';
            $message = $incident->alert_type === StockAlertIncident::OUT_OF_STOCK
                ? $label.' is still out of stock. Please acknowledge if you are handling this.'
                : $label.' still has '.$incident->current_sellable_quantity.' sellable units remaining.';
            $delivered = $this->alerts->send(
                $user,
                $type,
                $eventKey,
                $this->incidents->payload($incident, $title, $message),
                $title.' — '.$incident->inventory->product->sku,
                ProductInventoryResource::getUrl('view', ['record' => $incident->inventory]),
            );

            if ($this->incidents->notificationRecorded($user, $type, $eventKey, $delivered) !== null) {
                $recipient->forceFill([
                    'reminder_step' => $dueStep + 1,
                    'last_reminded_at' => now(),
                ])->save();
                $sent += (int) $delivered;
            }
        }

        return $sent;
    }

    private function escalateIfDue(StockAlertIncident $incident): int
    {
        if ($incident->alert_type !== StockAlertIncident::OUT_OF_STOCK || $incident->escalated_at !== null) {
            return 0;
        }

        $hours = (int) config('mobile.stock_alerts.out_of_stock_escalation_hours', 48);
        if ($incident->opened_at->addHours($hours)->isFuture()) {
            return 0;
        }

        $hasUnacknowledgedResponsible = $incident->recipients
            ->where('recipient_role', StockAlertIncidentRecipient::PRIMARY)
            ->contains(fn (StockAlertIncidentRecipient $recipient): bool => $recipient->acknowledged_at === null
                && in_array($recipient->user?->employee?->role, [EmployeeRole::Manager, EmployeeRole::Staff], true)
                && $this->recipientStillAuthorized($recipient, $incident));

        if (! $hasUnacknowledgedResponsible) {
            return 0;
        }

        $sent = 0;
        $recorded = false;
        foreach ($this->recipients->inventoryEscalation($incident->inventory) as $user) {
            $recipient = StockAlertIncidentRecipient::query()->firstOrCreate(
                ['stock_alert_incident_id' => $incident->id, 'user_id' => $user->id],
                ['recipient_role' => StockAlertIncidentRecipient::ESCALATION],
            );
            $type = $this->incidents->eventType($incident);
            $eventKey = 'stock-alert:'.$incident->incident_key.':escalation';
            $title = 'Out of Stock Escalation';
            $message = $this->incidents->productLabel($incident).' remains out of stock and is still unacknowledged.';
            $delivered = $this->alerts->send(
                $user,
                $type,
                $eventKey,
                $this->incidents->payload($incident, $title, $message),
                $title.' — '.$incident->inventory->product->sku,
                ProductInventoryResource::getUrl('view', ['record' => $incident->inventory]),
            );
            $notificationId = $this->incidents->notificationRecorded($user, $type, $eventKey, $delivered);

            if ($notificationId !== null) {
                $recipient->forceFill([
                    'recipient_role' => StockAlertIncidentRecipient::ESCALATION,
                    'initial_notification_id' => $notificationId,
                    'initial_notified_at' => $recipient->initial_notified_at ?? now(),
                ])->save();
                $recorded = true;
                $sent += (int) $delivered;
            }
        }

        if ($recorded) {
            $incident->forceFill(['escalated_at' => now()])->save();
        }

        return $sent;
    }

    private function recipientStillAuthorized(StockAlertIncidentRecipient $recipient, StockAlertIncident $incident): bool
    {
        $user = $recipient->user;

        return $user instanceof User
            && $user->employee?->status === true
            && $this->inventoryAuthorization->allows($user, InventoryPermission::View, $incident->inventory);
    }
}
