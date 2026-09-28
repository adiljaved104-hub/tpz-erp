<?php

namespace App\Services\Marketplace;

use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Services\Notifications\CriticalAlertDispatcher;

class MarketplaceIncidentReminderService
{
    public function __construct(private readonly MarketplaceIncidentService $incidents, private readonly CriticalAlertDispatcher $alerts) {}

    /** @return array{reminders:int,escalations:int} */
    public function evaluate(): array
    {
        $result = ['reminders' => 0, 'escalations' => 0];
        MarketplaceOperationIncident::query()->whereNull('resolved_at')->with(['product', 'platform', 'recipients.user.employee'])->chunkById(100, function ($incidents) use (&$result): void {
            foreach ($incidents as $incident) {
                $result['reminders'] += $this->remind($incident);
                $result['escalations'] += $this->escalate($incident);
            }
        });

        return $result;
    }

    private function remind(MarketplaceOperationIncident $incident): int
    {
        $schedule = config('marketplace_monitoring.reminder_hours', [2, 24]);
        $step = collect($schedule)->keys()->filter(fn (int $index): bool => $incident->opened_at->addHours((int) $schedule[$index])->isPast())->max();
        if (! is_int($step)) {
            return 0;
        }
        $sent = 0;
        foreach ($incident->recipients->where('recipient_role', MarketplaceOperationIncidentRecipient::PRIMARY) as $recipient) {
            if ($recipient->acknowledged_at !== null || $recipient->reminder_step > $step || ! $recipient->user?->employee?->status) {
                continue;
            }
            $type = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'marketplace.featured_offer_lost' : 'marketplace.stock_exposure';
            $title = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'Featured Offer Reminder' : 'Stock Exposure Reminder';
            $payload = ['category' => 'marketplace', 'event' => $type, 'title' => $title, 'message' => $incident->product->sku.' · '.$incident->product->name.' still requires attention.', 'reference' => $incident->product->sku, 'status' => 'Unresolved', 'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id, 'marketplace_operation_incident_id' => $incident->id, 'acknowledgment_required' => true];
            $eventKey = 'marketplace-incident:'.$incident->incident_key.':reminder:'.$step;
            $delivered = $this->alerts->send($recipient->user, $type, $eventKey, $payload, $title.' — '.$incident->product->sku, '/admin/marketplace-operations');
            $notificationId = $this->alerts->notificationId($recipient->user, $type, $eventKey);
            if ($delivered || $recipient->user->notifications()->whereKey($notificationId)->exists()) {
                $recipient->forceFill(['reminder_step' => $step + 1, 'last_reminded_at' => now()])->save();
                $sent += (int) $delivered;
            }
        }

        return $sent;
    }

    private function escalate(MarketplaceOperationIncident $incident): int
    {
        if ($incident->escalated_at !== null || $incident->opened_at->addHours((int) config('marketplace_monitoring.escalation_hours', 24))->isFuture()) {
            return 0;
        }
        if (! $incident->recipients->where('recipient_role', MarketplaceOperationIncidentRecipient::PRIMARY)->contains(fn ($recipient): bool => $recipient->acknowledged_at === null)) {
            return 0;
        }
        $sent = 0;
        foreach ($this->incidents->escalationRecipients() as $user) {
            $recipient = MarketplaceOperationIncidentRecipient::query()->firstOrCreate(['incident_id' => $incident->id, 'user_id' => $user->id], ['recipient_role' => MarketplaceOperationIncidentRecipient::ESCALATION]);
            $type = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'marketplace.featured_offer_lost' : 'marketplace.stock_exposure';
            $payload = ['category' => 'marketplace', 'event' => $type, 'title' => 'Marketplace Incident Escalation', 'message' => $incident->product->sku.' · '.$incident->product->name.' remains unresolved and unacknowledged.', 'reference' => $incident->product->sku, 'status' => 'Escalated', 'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id, 'marketplace_operation_incident_id' => $incident->id, 'acknowledgment_required' => true];
            $eventKey = 'marketplace-incident:'.$incident->incident_key.':escalation';
            $delivered = $this->alerts->send($user, $type, $eventKey, $payload, 'Marketplace Incident Escalation — '.$incident->product->sku, '/admin/marketplace-operations');
            if ($delivered || $user->notifications()->whereKey($this->alerts->notificationId($user, $type, $eventKey))->exists()) {
                $recipient->forceFill(['initial_notified_at' => $recipient->initial_notified_at ?? now()])->save();
                $sent += (int) $delivered;
            }
        }
        if ($incident->recipients()->where('recipient_role', MarketplaceOperationIncidentRecipient::ESCALATION)->whereNotNull('initial_notified_at')->exists()) {
            $incident->forceFill(['escalated_at' => now()])->save();
        }

        return $sent;
    }
}
