<?php

namespace App\Services\Marketplace;

use App\Enums\EmployeeRole;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Services\Notifications\CriticalAlertDispatcher;

class MarketplaceIncidentReminderService
{
    public function __construct(private readonly MarketplaceIncidentService $incidents, private readonly CriticalAlertDispatcher $alerts, private readonly MarketplaceMonitoringSettingsService $settings) {}

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
        $settings = $this->settings->effective();
        $interval = (int) $settings['employee_reminder_minutes'];
        $step = intdiv(max(0, $incident->opened_at->diffInMinutes(now(), false)), $interval) - 1;
        if ($step < 0) {
            return 0;
        }
        $sent = 0;
        foreach ($incident->recipients->where('recipient_role', MarketplaceOperationIncidentRecipient::PRIMARY) as $recipient) {
            if (($settings['acknowledgement_stops_reminders'] && $recipient->acknowledged_at !== null) || $recipient->reminder_step > $step || ! $recipient->user?->employee?->status) {
                continue;
            }
            $type = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'marketplace.featured_offer_lost' : 'marketplace.stock_exposure';
            $title = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'Featured Offer Reminder' : 'Stock Exposure Reminder';
            $payload = ['category' => 'marketplace', 'event' => $type, 'title' => $title, 'message' => $incident->product->sku.' · '.$incident->product->name.' still requires attention.', 'reference' => $incident->product->sku, 'status' => 'Unresolved', 'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id, 'marketplace_operation_incident_id' => $incident->id, 'acknowledgment_required' => true];
            $eventKey = 'marketplace-incident:'.$incident->incident_key.':reminder:'.$step;
            $delivered = $this->alerts->send($recipient->user, $type, $eventKey, $payload, $title.' — '.$incident->product->sku, '/admin/marketplace-operations', [], $settings['event_channels']);
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
        $settings = $this->settings->effective();
        if ($incident->escalated_at !== null || $incident->opened_at->addMinutes((int) $settings['escalation_threshold_minutes'])->isFuture()) {
            return 0;
        }
        if (! $incident->recipients->where('recipient_role', MarketplaceOperationIncidentRecipient::PRIMARY)->contains(fn ($recipient): bool => $recipient->acknowledged_at === null)) {
            return 0;
        }
        $sent = 0;
        $listing = $incident->listing;
        $managers = $settings['escalation_recipient_strategy'] === 'owner_admin' || $listing === null ? collect() : app(MarketplaceResponsibilityResolver::class)->assignments($listing)
            ->pluck('employee.user')->filter(fn ($user): bool => $user?->employee?->role === EmployeeRole::Manager)->values();
        foreach ($managers->merge($this->incidents->escalationRecipients())->unique('id') as $user) {
            $recipient = MarketplaceOperationIncidentRecipient::query()->firstOrCreate(['incident_id' => $incident->id, 'user_id' => $user->id], ['recipient_role' => MarketplaceOperationIncidentRecipient::ESCALATION]);
            $type = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST ? 'marketplace.featured_offer_lost' : 'marketplace.stock_exposure';
            if (! in_array('in_app', $settings['escalation_channels'], true) && ! in_array('email', $settings['escalation_channels'], true)) {
                continue;
            }
            $payload = ['category' => 'marketplace', 'event' => $type, 'title' => 'Marketplace Incident Escalation', 'message' => $incident->product->sku.' · '.$incident->product->name.' remains unresolved and unacknowledged.', 'reference' => $incident->product->sku, 'status' => 'Escalated', 'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id, 'marketplace_operation_incident_id' => $incident->id, 'acknowledgment_required' => true];
            $eventKey = 'marketplace-incident:'.$incident->incident_key.':escalation';
            $delivered = $this->alerts->send($user, $type, $eventKey, $payload, 'Marketplace Incident Escalation — '.$incident->product->sku, '/admin/marketplace-operations', [], $settings['escalation_channels']);
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
