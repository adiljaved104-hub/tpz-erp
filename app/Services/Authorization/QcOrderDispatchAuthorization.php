<?php

namespace App\Services\Authorization;

use App\Enums\EmployeeRole;
use App\Enums\OrderPermission;
use App\Enums\OrderStatus;
use App\Enums\ProductCondition;
use App\Enums\QcPermission;
use App\Models\Order;
use App\Models\User;
use App\Services\Qc\RenewedQcRequirement;
use Illuminate\Auth\Access\AuthorizationException;

/** Authorization for operational customer-order actions inside the QC workspace only. */
final class QcOrderDispatchAuthorization
{
    public function canViewQueue(User $actor): bool
    {
        return app(QcAuthorization::class)->allows($actor, QcPermission::ViewDispatchQueue)
            && ($this->isTechnician($actor) || app(OrderAuthorization::class)->allows($actor, OrderPermission::View));
    }

    public function authorizeQueue(User $actor): void
    {
        app(QcAuthorization::class)->authorize($actor, QcPermission::ViewDispatchQueue);
        if (! $this->isTechnician($actor)) {
            app(OrderAuthorization::class)->authorize($actor, OrderPermission::View);
        }
    }

    public function allowsOrder(User $actor, Order $order): bool
    {
        if (! $this->isTechnician($actor)) {
            return app(OrderAuthorization::class)->allows($actor, OrderPermission::View, $order);
        }

        if (! $this->canViewQueue($actor)) {
            return false;
        }

        if (! app(RenewedQcRequirement::class)->orderHasRequiredLine($order)) {
            return false;
        }

        if ($order->status === OrderStatus::Reserved) {
            return true;
        }

        return $order->status === OrderStatus::Fulfilled
            && $order->fulfillment()->exists()
            && $order->items()
                ->whereHas('product', fn ($query) => $query->where('condition', ProductCondition::Renewed->value))
                ->whereHas('qcAssignments', fn ($query) => $query->whereNull('released_at')->whereNotNull('active_device_id'))
                ->exists();
    }

    public function authorizeOrder(User $actor, Order $order): void
    {
        if (! $this->isTechnician($actor)) {
            app(OrderAuthorization::class)->authorize($actor, OrderPermission::View, $order);

            return;
        }

        throw_unless($this->allowsOrder($actor, $order), AuthorizationException::class);
    }

    public function canScan(User $actor): bool
    {
        return $this->canViewQueue($actor)
            && app(QcAuthorization::class)->allows($actor, QcPermission::ScanDispatch)
            && app(QcAuthorization::class)->allows($actor, QcPermission::ViewOrderAssignments)
            && app(QcAuthorization::class)->allows($actor, QcPermission::AssignOrderDevice);
    }

    public function canShip(User $actor): bool
    {
        return $this->canViewQueue($actor)
            && app(QcAuthorization::class)->allows($actor, QcPermission::ShipDispatch)
            && ($this->isTechnician($actor) || app(OrderAuthorization::class)->allows($actor, OrderPermission::Fulfill));
    }

    public function authorizeShip(User $actor, Order $order): void
    {
        $this->authorizeQueue($actor);
        $this->authorizeOrder($actor, $order);
        app(QcAuthorization::class)->authorize($actor, QcPermission::ShipDispatch);
        if (! $this->isTechnician($actor)) {
            app(OrderAuthorization::class)->authorize($actor, OrderPermission::Fulfill, $order);
        }
    }

    private function isTechnician(User $actor): bool
    {
        return $actor->employee?->role === EmployeeRole::Technician;
    }
}
