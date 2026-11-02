<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnPermission;
use App\Enums\CustomerReturnStatus;
use App\Enums\EmployeeRole;
use App\Enums\QcPermission;
use App\Models\CustomerReturn;
use App\Models\User;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Authorization\QcAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

class CustomerReturnEvidenceAuthorization
{
    public function canView(User $actor, ?CustomerReturn $return = null): bool
    {
        $qc = app(QcAuthorization::class)->allows($actor, QcPermission::ViewReturnEvidence);
        if ($return !== null && ! $this->inScope($actor, $return)) {
            return false;
        }
        if ($qc) {
            return true;
        }
        // Older claims can have a return record without a surviving Order
        // relation. The standard return authorization expects an Order.
        if ($return !== null && $return->order === null) {
            return false;
        }

        return app(CustomerReturnAuthorization::class)->allows($actor, CustomerReturnPermission::View, $return);
    }

    public function canRecord(User $actor, ?CustomerReturn $return = null): bool
    {
        return app(QcAuthorization::class)->allows($actor, QcPermission::RecordReturnEvidence)
            && ($return === null || ($this->inScope($actor, $return)
                && $return->received_at !== null
                && in_array($return->status, [CustomerReturnStatus::QcPending, CustomerReturnStatus::Completed], true)));
    }

    public function authorizeView(User $actor, ?CustomerReturn $return = null): void
    {
        throw_unless($this->canView($actor, $return), AuthorizationException::class);
    }

    public function authorizeRecord(User $actor, ?CustomerReturn $return = null): void
    {
        throw_unless($this->canRecord($actor, $return), AuthorizationException::class);
    }

    public function eligible(User $actor): Builder
    {
        $this->authorizeView($actor);

        return $this->scope(CustomerReturn::query(), $actor)
            ->whereNotNull('received_at')
            ->whereIn('status', [CustomerReturnStatus::QcPending->value, CustomerReturnStatus::Completed->value]);
    }

    private function inScope(User $actor, CustomerReturn $return): bool
    {
        return $this->scope(CustomerReturn::query(), $actor)->whereKey($return->getKey())->exists();
    }

    private function scope(Builder $query, User $actor): Builder
    {
        // Owner/Admin and the dedicated QC-only Technician work queue are broad.
        // Staff/Manager access continues to use the existing return responsibility scope.
        return in_array($actor->employee?->role, [EmployeeRole::Staff, EmployeeRole::Manager], true)
            ? app(CustomerReturnReadService::class)->query($actor)
            : $query;
    }
}
