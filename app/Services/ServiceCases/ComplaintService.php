<?php

namespace App\Services\ServiceCases;

use App\Enums\ComplaintCategory;
use App\Enums\ComplaintPermission;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Enums\WarrantyRepairSource;
use App\Exceptions\ComplaintException;
use App\Models\Complaint;
use App\Models\ComplaintStatusEvent;
use App\Models\User;
use App\Models\WarrantyRepair;
use App\Services\ActivityLogger;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\ReferenceSequenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

class ComplaintService
{
    public function __construct(private readonly ComplaintAuthorization $auth, private readonly ReferenceSequenceService $refs, private readonly ActivityLogger $activity, private readonly WarrantyRepairService $warranties, private readonly ServiceCaseAssigneeService $assignees, private readonly ServiceCaseOrderContextService $orders) {}

    public function create(array $data, User $actor): Complaint
    {
        $this->auth->authorize($actor, ComplaintPermission::Create);
        $data['category'] = $data['category'] instanceof ComplaintCategory ? $data['category']->value : $data['category'] ?? null;
        $v = Validator::make($data, ['category' => ['required', Rule::enum(ComplaintCategory::class)], 'description' => 'required|string|max:5000', 'quantity' => 'nullable|integer|min:1', 'idempotency_key' => 'required|uuid', 'marketplace_platform_id' => 'nullable|integer|exists:marketplace_platforms,id', 'order_id' => 'nullable|integer|exists:orders,id', 'customer_return_id' => 'nullable|integer|exists:customer_returns,id', 'warranty_repair_id' => 'nullable|integer|exists:warranty_repairs,id', 'product_id' => 'required|integer|exists:products,id'])->validate();
        $this->orders->assertProductBelongsToOrder(isset($v['order_id']) ? (int) $v['order_id'] : null, (int) $v['product_id'], $actor);
        $this->auth->authorize($actor, ComplaintPermission::Create, new Complaint([
            'order_id' => isset($v['order_id']) ? (int) $v['order_id'] : null,
            'customer_return_id' => isset($v['customer_return_id']) ? (int) $v['customer_return_id'] : null,
            'product_id' => (int) $v['product_id'],
            'marketplace_platform_id' => isset($v['marketplace_platform_id']) ? (int) $v['marketplace_platform_id'] : null,
        ]));
        if ($existing = Complaint::query()->where('idempotency_key', $v['idempotency_key'])->first()) {
            return $existing;
        }$ref = $this->refs->nextComplaintReference();

        $complaint = DB::transaction(function () use ($v, $actor, $ref) {
            $c = Complaint::query()->create($v + ['reference' => $ref, 'status' => ComplaintStatus::Open, 'opened_at' => now(), 'created_by_user_id' => $actor->id]);
            $this->event($c, null, ComplaintStatus::Open, null, $actor);
            $this->activity->log('complaint.created', $actor, $c, ['complaint_reference' => $c->reference, 'category' => $c->category->value]);

            return $c;
        });

        try {
            return $this->assignees->autoAssignComplaint($complaint, $actor);
        } catch (Throwable $exception) {
            report($exception);

            return $complaint->refresh();
        }
    }

    public function transition(Complaint $case, ComplaintStatus $to, User $actor, ?ComplaintResolution $resolution = null, ?string $note = null): Complaint
    {
        $this->auth->authorize($actor, $to === ComplaintStatus::Resolved ? ComplaintPermission::Resolve : ComplaintPermission::Update, $case);
        if ($to === ComplaintStatus::Cancelled && blank($note)) {
            throw new ComplaintException('A cancellation reason is required.');
        }
        if ($to === ComplaintStatus::Resolved && $resolution === null) {
            throw new ComplaintException('A resolution is required to resolve this Complaint.');
        }

        return DB::transaction(function () use ($case, $to, $actor, $resolution, $note) {
            $locked = Complaint::query()->lockForUpdate()->findOrFail($case->id);
            $from = $locked->status;
            if (in_array($from, [ComplaintStatus::Closed, ComplaintStatus::Cancelled], true) || $from === $to) {
                throw new ComplaintException('This Complaint transition is no longer valid.');
            }$locked->forceFill(['status' => $to, 'resolution' => $resolution ?? $locked->resolution, 'resolution_note' => $note ?? $locked->resolution_note, 'resolved_at' => in_array($to, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true) ? now() : $locked->resolved_at])->save();
            $this->event($locked, $from, $to, $note, $actor);
            $this->activity->log($to === ComplaintStatus::Resolved ? 'complaint.resolved' : 'complaint.status_changed', $actor, $locked, ['complaint_reference' => $locked->reference, 'from_status' => $from->value, 'to_status' => $to->value]);

            return $locked->refresh();
        });
    }

    public function convertToWarranty(Complaint $case, array $data, User $actor): WarrantyRepair
    {
        if ($case->warranty_repair_id !== null) {
            return $case->warrantyRepair;
        }
        $w = $this->warranties->create($data + ['marketplace_platform_id' => $case->marketplace_platform_id, 'order_id' => $case->order_id, 'customer_return_id' => $case->customer_return_id, 'product_id' => $case->product_id, 'quantity' => $case->quantity ?? 1, 'source' => WarrantyRepairSource::Complaint->value, 'issue_description' => $case->description, 'received_at' => now()], $actor);
        $case->forceFill(['warranty_repair_id' => $w->id])->save();
        $this->activity->log('complaint.linked_to_warranty', $actor, $case, ['complaint_reference' => $case->reference, 'warranty_repair_id' => $w->id]);

        return $w;
    }

    private function event(Complaint $case, ?ComplaintStatus $from, ComplaintStatus $to, ?string $note, User $actor): void
    {
        ComplaintStatusEvent::query()->create(['complaint_id' => $case->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'changed_by_user_id' => $actor->id, 'changed_at' => now()]);
    }
}
