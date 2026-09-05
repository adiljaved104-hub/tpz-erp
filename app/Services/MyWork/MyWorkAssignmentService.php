<?php

namespace App\Services\MyWork;

use App\DTOs\MyWork\MyWorkItem;
use App\Enums\ComplaintPermission;
use App\Enums\SafetClaimPermission;
use App\Enums\WarrantyRepairPermission;
use App\Models\Complaint;
use App\Models\SafetClaim;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Authorization\WarrantyRepairAuthorization;
use App\Services\Claims\SafetClaimAssigneeService;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class MyWorkAssignmentService
{
    public function __construct(
        private readonly SafetClaimAuthorization $claimAuthorization,
        private readonly WarrantyRepairAuthorization $warrantyAuthorization,
        private readonly ComplaintAuthorization $complaintAuthorization,
        private readonly SafetClaimAssigneeService $claimAssignees,
        private readonly ServiceCaseAssigneeService $caseAssignees,
    ) {}

    /**
     * @param  Collection<int, MyWorkItem>  $items
     * @return array<string, array{current: ?int, current_label: ?string, current_eligible: bool, options: array<int, string>}>
     */
    public function controls(User $actor, Collection $items): array
    {
        $grouped = $items->groupBy(fn ($item): string => str($item->key)->before(':')->toString());
        $controls = [];

        $claims = SafetClaim::query()
            ->with(['damagedStockEvent', 'assignedTo:id,name,email'])
            ->whereKey($this->recordIds($grouped->get('claim', collect())))
            ->get()->keyBy('id');
        foreach ($grouped->get('claim', collect()) as $item) {
            $claim = $claims->get($item->recordId);
            if ($claim !== null && $this->claimAuthorization->allows($actor, SafetClaimPermission::Assign, $claim)) {
                $controls[$item->key] = $this->control(
                    $claim->assigned_to_user_id,
                    $claim->assignedTo?->name,
                    $this->claimAssignees->eligibleUsers($claim),
                );
            }
        }

        $warranties = WarrantyRepair::query()
            ->with(['assignedTo:id,name,email'])
            ->whereKey($this->recordIds($grouped->get('warranty', collect())))
            ->get()->keyBy('id');
        foreach ($grouped->get('warranty', collect()) as $item) {
            $case = $warranties->get($item->recordId);
            if ($case !== null && $this->warrantyAuthorization->allows($actor, WarrantyRepairPermission::Assign, $case)) {
                $controls[$item->key] = $this->control(
                    $case->assigned_to_user_id,
                    $case->assignedTo?->name,
                    $this->caseAssignees->eligibleWarrantyUsers($case),
                );
            }
        }

        $complaints = Complaint::query()
            ->with(['assignedTo:id,name,email', 'order:id,warehouse_id', 'customerReturn:id,receiving_warehouse_id'])
            ->whereKey($this->recordIds($grouped->get('complaint', collect())))
            ->get()->keyBy('id');
        foreach ($grouped->get('complaint', collect()) as $item) {
            $case = $complaints->get($item->recordId);
            if ($case !== null && $this->complaintAuthorization->allows($actor, ComplaintPermission::Assign, $case)) {
                $controls[$item->key] = $this->control(
                    $case->assigned_to_user_id,
                    $case->assignedTo?->name,
                    $this->caseAssignees->eligibleComplaintUsers($case),
                );
            }
        }

        return $controls;
    }

    public function assign(User $actor, string $itemKey, ?int $userId): void
    {
        [$type, $recordId] = $this->parseKey($itemKey);

        match ($type) {
            'claim' => $this->claimAssignees->assign(
                SafetClaim::query()->with('damagedStockEvent')->findOrFail($recordId),
                $userId,
                $actor,
            ),
            'warranty' => $this->caseAssignees->assignWarranty(
                WarrantyRepair::query()->findOrFail($recordId),
                $userId,
                $actor,
            ),
            'complaint' => $this->caseAssignees->assignComplaint(
                Complaint::query()->with(['order', 'customerReturn'])->findOrFail($recordId),
                $userId,
                $actor,
            ),
            default => throw ValidationException::withMessages(['assignment' => 'This work item does not support assignment.']),
        };
    }

    /** @param Collection<int, mixed> $items
     * @return array<int, int>
     */
    private function recordIds(Collection $items): array
    {
        return $items->pluck('recordId')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * @param  Collection<int, User>  $eligible
     * @return array{current: ?int, current_label: ?string, current_eligible: bool, options: array<int, string>}
     */
    private function control(?int $current, ?string $currentLabel, Collection $eligible): array
    {
        return [
            'current' => $current,
            'current_label' => $currentLabel,
            'current_eligible' => $current === null || $eligible->contains('id', $current),
            'options' => $eligible->mapWithKeys(fn (User $user): array => [$user->id => "{$user->name} — {$user->email}"])->all(),
        ];
    }

    /** @return array{string, int} */
    private function parseKey(string $itemKey): array
    {
        $parts = explode(':', $itemKey, 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[1]) || (int) $parts[1] < 1) {
            throw ValidationException::withMessages(['assignment' => 'The selected work item is invalid.']);
        }

        return [$parts[0], (int) $parts[1]];
    }
}
