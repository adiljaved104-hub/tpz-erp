<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MarketplaceIncidentAcknowledgementService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /** @return array<string, mixed>|null */
    public function context(User $user, DatabaseNotification $notification): ?array
    {
        $recipient = $this->recipient($user, $notification);

        return $recipient instanceof MarketplaceOperationIncidentRecipient ? $this->serialize($recipient) : null;
    }

    /** @return array<string, mixed> */
    public function acknowledge(User $user, DatabaseNotification $notification): array
    {
        return DB::transaction(function () use ($user, $notification): array {
            $recipient = $this->recipient($user, $notification, true);
            if (! $recipient instanceof MarketplaceOperationIncidentRecipient) {
                throw new NotFoundHttpException;
            }
            if ($recipient->acknowledged_at === null) {
                $recipient->forceFill(['acknowledged_at' => now()])->save();
                $this->activity->log('marketplace_incident.acknowledged', $user, $recipient->incident, ['incident_id' => $recipient->incident_id, 'incident_type' => $recipient->incident->incident_type, 'recipient_user_id' => $user->id]);
            }

            return $this->serialize($recipient->refresh());
        });
    }

    private function recipient(User $user, DatabaseNotification $notification, bool $lock = false): ?MarketplaceOperationIncidentRecipient
    {
        $id = $notification->data['marketplace_operation_incident_id'] ?? null;
        if (! is_numeric($id) || ! ($notification->data['acknowledgment_required'] ?? false)) {
            return null;
        }
        $query = MarketplaceOperationIncidentRecipient::query()->where('incident_id', (int) $id)->where('user_id', $user->id)->with('incident');

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    /** @return array<string, mixed> */
    private function serialize(MarketplaceOperationIncidentRecipient $recipient): array
    {
        return ['acknowledgment_required' => true, 'acknowledged' => $recipient->acknowledged_at !== null, 'acknowledged_at' => $recipient->acknowledged_at?->toIso8601String(), 'incident' => ['key' => $recipient->incident->incident_key, 'alert_type' => $recipient->incident->incident_type, 'opened_at' => $recipient->incident->opened_at?->toIso8601String(), 'resolved' => $recipient->incident->resolved_at !== null, 'resolved_at' => $recipient->incident->resolved_at?->toIso8601String()]];
    }
}
