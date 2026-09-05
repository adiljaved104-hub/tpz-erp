<?php

namespace App\Services\ServiceCases;

use App\Enums\ComplaintPermission;
use App\Enums\WarrantyRepairPermission;
use App\Exceptions\ComplaintException;
use App\Exceptions\WarrantyRepairException;
use App\Models\Complaint;
use App\Models\Employee;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\ActivityLogger;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Orders\OrderResponsibilityScopeService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceCaseAssigneeService
{
    /** @var array<string, Collection<int, User>> */
    private array $warrantyEligibility = [];

    /** @var array<string, Collection<int, User>> */
    private array $complaintEligibility = [];

    public function __construct(private readonly WarrantyRepairAuthorization $warrantyAuth, private readonly ComplaintAuthorization $complaintAuth, private readonly OrderResponsibilityScopeService $scope, private readonly ActivityLogger $activity) {}

    public function autoAssignWarranty(WarrantyRepair $case, User $actor): WarrantyRepair
    {
        $users = $this->eligibleWarrantyUsers($case);
        if ($users->count() === 1) {
            return $this->assignWarranty($case, $users->sole()->id, $actor, false);
        }

        return $case;
    }

    public function autoAssignComplaint(Complaint $case, User $actor): Complaint
    {
        $users = $this->eligibleComplaintUsers($case);
        if ($users->count() === 1) {
            return $this->assignComplaint($case, $users->sole()->id, $actor, false);
        }

        return $case;
    }

    public function assignWarranty(WarrantyRepair $case, ?int $userId, User $actor, bool $authorize = true): WarrantyRepair
    {
        if ($authorize) {
            $this->warrantyAuth->authorize($actor, WarrantyRepairPermission::Assign, $case);
        }

        return DB::transaction(function () use ($case, $userId, $actor): WarrantyRepair {
            $locked = WarrantyRepair::query()->lockForUpdate()->findOrFail($case->id);
            $old = $locked->assigned_to_user_id;
            if ($userId !== null && ! $this->eligibleWarrantyUsers($locked)->contains('id', $userId)) {
                throw new WarrantyRepairException('This employee is not eligible for this Warranty / Repair case.');
            }
            $locked->forceFill(['assigned_to_user_id' => $userId])->save();
            $this->activity->log('warranty.assigned', $actor, $locked, ['warranty_reference' => $locked->reference, 'previous_assignee_user_id' => $old, 'new_assignee_user_id' => $userId]);

            return $locked->refresh();
        });
    }

    public function assignComplaint(Complaint $case, ?int $userId, User $actor, bool $authorize = true): Complaint
    {
        if ($authorize) {
            $this->complaintAuth->authorize($actor, ComplaintPermission::Assign, $case);
        }

        return DB::transaction(function () use ($case, $userId, $actor): Complaint {
            $locked = Complaint::query()->lockForUpdate()->findOrFail($case->id);
            $old = $locked->assigned_to_user_id;
            if ($userId !== null && ! $this->eligibleComplaintUsers($locked)->contains('id', $userId)) {
                throw new ComplaintException('This employee is not eligible for this Complaint.');
            }
            $locked->forceFill(['assigned_to_user_id' => $userId])->save();
            $this->activity->log('complaint.assigned', $actor, $locked, ['complaint_reference' => $locked->reference, 'previous_assignee_user_id' => $old, 'new_assignee_user_id' => $userId]);

            return $locked->refresh();
        });
    }

    public function eligibleWarrantyUsers(WarrantyRepair $case): Collection
    {
        $key = $case->getKey().':'.($case->updated_at?->getTimestamp() ?? 'new');

        return $this->warrantyEligibility[$key] ??= $this->eligible($case->product_id, $case->marketplace_platform_id, $case->warehouse_id, fn (User $user) => $this->warrantyAuth->allows($user, WarrantyRepairPermission::View, $case) && $this->warrantyAuth->allows($user, WarrantyRepairPermission::UpdateStatus, $case));
    }

    public function eligibleComplaintUsers(Complaint $case): Collection
    {
        $key = $case->getKey().':'.($case->updated_at?->getTimestamp() ?? 'new');
        if (isset($this->complaintEligibility[$key])) {
            return $this->complaintEligibility[$key];
        }

        $warehouse = $case->order?->warehouse_id ?? $case->customerReturn?->receiving_warehouse_id;
        if ($case->product_id === null || $warehouse === null) {
            return $this->complaintEligibility[$key] = collect();
        }

        return $this->complaintEligibility[$key] = $this->eligible($case->product_id, $case->marketplace_platform_id, $warehouse, fn (User $user) => $this->complaintAuth->allows($user, ComplaintPermission::View, $case) && $this->complaintAuth->allows($user, ComplaintPermission::Update, $case));
    }

    public function warrantyOptions(WarrantyRepair $case): array
    {
        return $this->eligibleWarrantyUsers($case)->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->email}"])->all();
    }

    public function complaintOptions(Complaint $case): array
    {
        return $this->eligibleComplaintUsers($case)->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} — {$user->email}"])->all();
    }

    public function warrantyPlaceholder(WarrantyRepair $case): string
    {
        return $this->assignmentPlaceholder($this->eligibleWarrantyUsers($case));
    }

    public function complaintPlaceholder(Complaint $case): string
    {
        return $this->assignmentPlaceholder($this->eligibleComplaintUsers($case));
    }

    private function eligible(int $productId, ?int $platformId, int $warehouseId, callable $permission): Collection
    {
        return Employee::query()->with('user')->where('status', true)->whereNotNull('user_id')->get()->pluck('user')->filter(fn (?User $user) => $user !== null && $permission($user) && $this->scope->hasMatchingActiveResponsibility($user, $productId, $platformId, $warehouseId))->values();
    }

    private function assignmentPlaceholder(Collection $eligible): string
    {
        return match (true) {
            $eligible->isEmpty() => 'No eligible employee',
            $eligible->count() > 1 => 'Unassigned — multiple eligible employees',
            default => 'Unassigned',
        };
    }
}
