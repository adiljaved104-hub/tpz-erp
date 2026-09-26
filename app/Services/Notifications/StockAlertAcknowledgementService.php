<?php

namespace App\Services\Notifications;

use App\Enums\InventoryPermission;
use App\Models\StockAlertIncidentRecipient;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\InventoryAuthorization;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StockAlertAcknowledgementService
{
    public function __construct(
        private readonly InventoryAuthorization $inventoryAuthorization,
        private readonly ActivityLogger $activity,
    ) {}

    /** @return array<string, mixed>|null */
    public function context(User $user, DatabaseNotification $notification): ?array
    {
        $recipient = $this->authorizedRecipient($user, $notification);
        if (! $recipient instanceof StockAlertIncidentRecipient) {
            return null;
        }

        return $this->serialize($recipient);
    }

    /** @return array<string, mixed> */
    public function acknowledge(User $user, DatabaseNotification $notification): array
    {
        return DB::transaction(function () use ($user, $notification): array {
            $recipient = $this->authorizedRecipient($user, $notification, true);
            if (! $recipient instanceof StockAlertIncidentRecipient) {
                throw new NotFoundHttpException;
            }

            if ($recipient->acknowledged_at === null) {
                $recipient->forceFill(['acknowledged_at' => now()])->save();
                $this->activity->log('stock_alert.acknowledged', $user, $recipient->incident, [
                    'incident_id' => $recipient->incident->id,
                    'alert_type' => $recipient->incident->alert_type,
                    'inventory_id' => $recipient->incident->product_inventory_id,
                    'recipient_user_id' => $user->id,
                ]);
            }

            return $this->serialize($recipient->refresh());
        });
    }

    private function authorizedRecipient(User $user, DatabaseNotification $notification, bool $lock = false): ?StockAlertIncidentRecipient
    {
        $incidentId = $notification->data['stock_alert_incident_id'] ?? null;
        if (! is_numeric($incidentId) || ! ($notification->data['acknowledgment_required'] ?? false)) {
            return null;
        }

        $query = StockAlertIncidentRecipient::query()
            ->where('stock_alert_incident_id', (int) $incidentId)
            ->where('user_id', $user->id)
            ->with('incident.inventory');
        $recipient = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $recipient instanceof StockAlertIncidentRecipient) {
            return null;
        }

        return $this->inventoryAuthorization->allows($user, InventoryPermission::View, $recipient->incident->inventory)
            ? $recipient
            : null;
    }

    /** @return array<string, mixed> */
    private function serialize(StockAlertIncidentRecipient $recipient): array
    {
        $incident = $recipient->incident;

        return [
            'acknowledgment_required' => true,
            'acknowledged' => $recipient->acknowledged_at !== null,
            'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(),
            'incident' => [
                'key' => $incident->incident_key,
                'alert_type' => $incident->alert_type,
                'opened_at' => $incident->opened_at?->toIso8601String(),
                'resolved' => $incident->resolved_at !== null,
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'current_sellable_quantity' => $incident->current_sellable_quantity,
            ],
        ];
    }
}
