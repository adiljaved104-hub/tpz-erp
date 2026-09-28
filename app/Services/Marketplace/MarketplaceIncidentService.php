<?php

namespace App\Services\Marketplace;

use App\Enums\EmployeeRole;
use App\Models\Employee;
use App\Models\MarketplaceOperationIncident;
use App\Models\MarketplaceOperationIncidentRecipient;
use App\Models\ProductMarketplaceListing;
use App\Models\ResponsibilityAssignment;
use App\Models\User;
use App\Services\Notifications\CriticalAlertDispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MarketplaceIncidentService
{
    public function __construct(
        private readonly MarketplaceResponsibilityResolver $responsibilities,
        private readonly CriticalAlertDispatcher $alerts,
    ) {}

    public function openFeaturedOfferLost(ProductMarketplaceListing $listing): MarketplaceOperationIncident
    {
        $assignment = $this->responsibilities->assignments($listing)->first();

        return $this->open($listing, MarketplaceOperationIncident::FEATURED_OFFER_LOST, 'featured-offer:listing:'.$listing->id, $assignment, null, null, true);
    }

    public function reconcileStockExposure(ProductMarketplaceListing $listing, ResponsibilityAssignment $assignment, int $exposed, int $usable): ?MarketplaceOperationIncident
    {
        $key = 'stock-exposure:product:'.$listing->product_id.':responsibility:'.$assignment->id;
        if ($exposed <= $usable) {
            $this->resolveByKey($key);

            return null;
        }

        return $this->open($listing, MarketplaceOperationIncident::STOCK_EXPOSURE, $key, $assignment, $exposed, $usable);
    }

    public function resolveFeaturedOffer(ProductMarketplaceListing $listing): void
    {
        $this->resolveByKey('featured-offer:listing:'.$listing->id, true);
    }

    public function resolveByKey(string $activeKey, bool $regained = false): void
    {
        DB::transaction(function () use ($activeKey, $regained): void {
            $incident = MarketplaceOperationIncident::query()->where('active_key', $activeKey)->lockForUpdate()->first();
            if (! $incident instanceof MarketplaceOperationIncident) {
                return;
            }
            $incident->forceFill(['active_key' => null, 'last_evaluated_at' => now(), 'regained_at' => $regained ? now() : $incident->regained_at, 'resolved_at' => now()])->save();
        });
    }

    /** @return Collection<int, User> */
    public function escalationRecipients(): Collection
    {
        return Employee::query()->where('status', true)->whereNotNull('user_id')->whereIn('role', [EmployeeRole::Owner->value, EmployeeRole::Admin->value])
            ->with('user')->get()->pluck('user')->filter(fn ($user): bool => $user instanceof User)->unique('id')->values();
    }

    private function open(ProductMarketplaceListing $listing, string $type, string $activeKey, ?ResponsibilityAssignment $assignment, ?int $exposed, ?int $usable, bool $notifyAllMatching = false): MarketplaceOperationIncident
    {
        $listing->loadMissing('product.brandRelation', 'platform');
        $incident = DB::transaction(function () use ($listing, $type, $activeKey, $assignment, $exposed, $usable): MarketplaceOperationIncident {
            $existing = MarketplaceOperationIncident::query()->where('active_key', $activeKey)->lockForUpdate()->first();
            if ($existing instanceof MarketplaceOperationIncident) {
                $existing->forceFill(['last_evaluated_at' => now(), 'exposed_listing_count' => $exposed, 'usable_quantity' => $usable])->save();

                return $existing->refresh();
            }

            try {
                return MarketplaceOperationIncident::query()->create([
                    'incident_key' => (string) Str::uuid(), 'product_id' => $listing->product_id, 'listing_id' => $listing->id,
                    'marketplace_platform_id' => $listing->marketplace_platform_id, 'responsibility_assignment_id' => $assignment?->id,
                    'responsible_employee_id' => $assignment?->employee_id, 'responsible_team_id' => $assignment?->employee?->team_id,
                    'incident_type' => $type, 'active_key' => $activeKey, 'exposed_listing_count' => $exposed,
                    'usable_quantity' => $usable, 'opened_at' => now(), 'last_evaluated_at' => now(),
                ]);
            } catch (QueryException) {
                return MarketplaceOperationIncident::query()->where('active_key', $activeKey)->firstOrFail();
            }
        });

        $recipients = ! $notifyAllMatching && $assignment?->employee?->user instanceof User
            ? collect([$assignment->employee->user])
            : $this->responsibilities->assignments($listing)->pluck('employee.user')->filter(fn ($user): bool => $user instanceof User)->unique('id')->values();
        if ($recipients->isEmpty()) {
            $recipients = $this->escalationRecipients();
        }

        foreach ($recipients as $user) {
            $this->notifyInitial($incident->loadMissing('product', 'platform'), $user, $assignment === null && $incident->responsible_employee_id === null ? MarketplaceOperationIncidentRecipient::ESCALATION : MarketplaceOperationIncidentRecipient::PRIMARY);
        }

        return $incident;
    }

    private function notifyInitial(MarketplaceOperationIncident $incident, User $user, string $role): void
    {
        $recipient = MarketplaceOperationIncidentRecipient::query()->firstOrCreate(['incident_id' => $incident->id, 'user_id' => $user->id], ['recipient_role' => $role]);
        if ($recipient->initial_notified_at !== null) {
            return;
        }
        $featured = $incident->incident_type === MarketplaceOperationIncident::FEATURED_OFFER_LOST;
        $type = $featured ? 'marketplace.featured_offer_lost' : 'marketplace.stock_exposure';
        $title = $featured ? 'Featured Offer Lost' : 'Marketplace Stock Exposure';
        $message = $featured
            ? $incident->product->sku.' · '.$incident->product->name.' no longer holds the Featured Offer on '.$incident->platform->name.'.'
            : $incident->product->sku.' · '.$incident->product->name.' has '.$incident->exposed_listing_count.' active listings but only '.$incident->usable_quantity.' usable assigned units.';
        $payload = [
            'category' => 'marketplace', 'event' => $type, 'title' => $title, 'message' => $message,
            'reference' => $incident->product->sku, 'status' => $featured ? 'Featured Offer Lost' : 'Stock Exposure',
            'target_type' => 'marketplace_operation_incident', 'target_id' => $incident->id,
            'marketplace_operation_incident_id' => $incident->id, 'acknowledgment_required' => true,
        ];
        $eventKey = 'marketplace-incident:'.$incident->incident_key.':initial';
        $sent = $this->alerts->send($user, $type, $eventKey, $payload, $title.' — '.$incident->product->sku, '/admin/marketplace-operations');
        $notificationId = $this->alerts->notificationId($user, $type, $eventKey);
        if ($sent || $user->notifications()->whereKey($notificationId)->exists()) {
            $recipient->forceFill(['initial_notification_id' => $notificationId, 'initial_notified_at' => now()])->save();
        }
    }
}
