<?php

namespace App\Services\Notifications;

use App\Filament\Resources\ProductInventories\ProductInventoryResource;
use App\Models\ProductInventory;
use App\Models\StockAlertIncident;
use App\Models\StockAlertIncidentRecipient;
use App\Models\User;
use App\Services\Mobile\StockStatus;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAlertIncidentService
{
    public function __construct(
        private readonly StockStatus $stockStatus,
        private readonly CriticalAlertRecipientResolver $recipients,
        private readonly CriticalAlertDispatcher $alerts,
    ) {}

    public function reconcile(ProductInventory $inventory): ?StockAlertIncident
    {
        $inventory->refresh()->loadMissing(['product', 'warehouse']);
        $quantity = $inventory->sellableQuantity();
        $wantedType = match (true) {
            $quantity <= 0 => StockAlertIncident::OUT_OF_STOCK,
            $quantity <= $this->stockStatus->low() => StockAlertIncident::LOW_STOCK,
            default => null,
        };

        $incident = DB::transaction(function () use ($inventory, $quantity, $wantedType): ?StockAlertIncident {
            $active = StockAlertIncident::query()
                ->where('product_inventory_id', $inventory->id)
                ->whereNull('resolved_at')
                ->lockForUpdate()
                ->get();

            foreach ($active as $existing) {
                if ($existing->alert_type !== $wantedType) {
                    $existing->forceFill([
                        'active_key' => null,
                        'current_sellable_quantity' => $quantity,
                        'last_evaluated_at' => now(),
                        'resolved_at' => now(),
                    ])->save();
                }
            }

            if ($wantedType === null) {
                return null;
            }

            $current = $active->firstWhere('alert_type', $wantedType);
            if ($current instanceof StockAlertIncident) {
                $current->forceFill([
                    'current_sellable_quantity' => $quantity,
                    'last_evaluated_at' => now(),
                ])->save();

                return $current->refresh();
            }

            try {
                return StockAlertIncident::query()->create([
                    'incident_key' => (string) Str::uuid(),
                    'product_inventory_id' => $inventory->id,
                    'alert_type' => $wantedType,
                    'active_key' => $inventory->id.':'.$wantedType,
                    'opened_sellable_quantity' => $quantity,
                    'current_sellable_quantity' => $quantity,
                    'opened_at' => now(),
                    'last_evaluated_at' => now(),
                ]);
            } catch (QueryException) {
                return StockAlertIncident::query()
                    ->where('active_key', $inventory->id.':'.$wantedType)
                    ->firstOrFail();
            }
        });

        if ($incident instanceof StockAlertIncident) {
            $this->synchronizePrimaryRecipients($incident->loadMissing('inventory.product', 'inventory.warehouse'));
        }

        return $incident;
    }

    public function synchronizePrimaryRecipients(StockAlertIncident $incident): void
    {
        if ($incident->resolved_at !== null) {
            return;
        }

        $incident->loadMissing('inventory.product', 'inventory.warehouse');
        $eventType = $this->eventType($incident);

        foreach ($this->recipients->inventory($incident->inventory, $eventType) as $user) {
            $recipient = StockAlertIncidentRecipient::query()->firstOrCreate(
                ['stock_alert_incident_id' => $incident->id, 'user_id' => $user->id],
                ['recipient_role' => StockAlertIncidentRecipient::PRIMARY],
            );

            if ($recipient->initial_notified_at === null) {
                $this->sendInitial($incident, $recipient, $user);
            }
        }
    }

    public function eventType(StockAlertIncident $incident): string
    {
        return 'inventory.'.$incident->alert_type;
    }

    public function notificationRecorded(User $user, string $type, string $eventKey, bool $sent): ?string
    {
        $notificationId = $this->alerts->notificationId($user, $type, $eventKey);

        return $sent || $user->notifications()->whereKey($notificationId)->exists()
            ? $notificationId
            : null;
    }

    /** @return array<string, mixed> */
    public function payload(StockAlertIncident $incident, string $title, string $message, bool $acknowledgmentRequired = true): array
    {
        $inventory = $incident->inventory;
        $product = $inventory->product;

        return [
            'category' => 'inventory',
            'event' => $this->eventType($incident),
            'title' => $title,
            'message' => $message,
            'reference' => $product->sku,
            'status' => $incident->alert_type === StockAlertIncident::OUT_OF_STOCK ? 'Out of Stock' : 'Low Stock',
            'target_type' => 'product_inventory',
            'target_id' => $inventory->id,
            'stock_alert_incident_id' => $incident->id,
            'stock_alert_incident_key' => $incident->incident_key,
            'alert_type' => $incident->alert_type,
            'acknowledgment_required' => $acknowledgmentRequired,
        ];
    }

    public function productLabel(StockAlertIncident $incident): string
    {
        return $incident->inventory->product->sku.' · '.$incident->inventory->product->name;
    }

    private function sendInitial(StockAlertIncident $incident, StockAlertIncidentRecipient $recipient, User $user): void
    {
        $type = $this->eventType($incident);
        $eventKey = 'stock-alert:'.$incident->incident_key.':initial';
        $label = $this->productLabel($incident);
        $outOfStock = $incident->alert_type === StockAlertIncident::OUT_OF_STOCK;
        $title = $outOfStock ? 'Out of Stock' : 'Low Stock';
        $message = $outOfStock
            ? $label.' is out of stock.'
            : $label.' has '.$incident->current_sellable_quantity.' sellable units remaining.';
        $sent = $this->alerts->send(
            $user,
            $type,
            $eventKey,
            $this->payload($incident, $title, $message),
            $title.' — '.$incident->inventory->product->sku,
            ProductInventoryResource::getUrl('view', ['record' => $incident->inventory]),
        );
        $notificationId = $this->notificationRecorded($user, $type, $eventKey, $sent);

        if ($notificationId !== null) {
            $recipient->forceFill([
                'initial_notification_id' => $notificationId,
                'initial_notified_at' => now(),
            ])->save();
        }
    }
}
