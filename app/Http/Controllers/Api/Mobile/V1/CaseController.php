<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ComplaintPermission;
use App\Enums\ComplaintResolution;
use App\Enums\ComplaintStatus;
use App\Enums\SafetClaimPermission;
use App\Enums\SafetClaimStatus;
use App\Exceptions\ComplaintException;
use App\Exceptions\SafetClaimException;
use App\Models\Complaint;
use App\Models\SafetClaim;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Claims\SafetClaimAssigneeService;
use App\Services\Claims\SafetClaimService;
use App\Services\ServiceCases\ComplaintService;
use App\Services\ServiceCases\ServiceCaseAssigneeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaseController extends MobileController
{
    public function index(Request $request, string $kind): JsonResponse
    {
        $user = $request->user();
        if ($kind === 'claims') {
            $auth = app(SafetClaimAuthorization::class);
            $auth->authorize($user, SafetClaimPermission::View);
            $query = $auth->scopeQuery(SafetClaim::query()->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name']), $user);
            if ($request->input('filter') === 'open') {
                $query->whereNotIn('status', ['rejected', 'closed', 'not_eligible']);
            }
            $search = ['reference', 'claim_reason', 'external_claim_reference'];
        } elseif ($kind === 'complaints') {
            $auth = app(ComplaintAuthorization::class);
            $auth->authorize($user, ComplaintPermission::View);
            $query = $auth->scopeQuery(Complaint::query()->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name']), $user);
            if ($request->input('filter') === 'open') {
                $query->whereNotIn('status', ['resolved', 'closed', 'cancelled']);
            }
            $search = ['reference', 'description'];
        } else {
            abort(404);
        }

        return $this->page($request, $query->orderByDesc('id'), $search, fn ($case) => $this->present($request, $kind, $case));
    }

    public function show(Request $request, string $kind, int $record): JsonResponse
    {
        if ($kind === 'claims') {
            $claim = app(SafetClaimAuthorization::class)->scopeQuery(
                SafetClaim::query()->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name']),
                $request->user(),
            )->findOrFail($record);
            app(SafetClaimAuthorization::class)->authorize($request->user(), SafetClaimPermission::View, $claim);
            $case = $claim;
        } elseif ($kind === 'complaints') {
            $complaint = app(ComplaintAuthorization::class)->scopeQuery(
                Complaint::query()->with(['product:id,sku,name', 'platform:id,name', 'assignedTo:id,name', 'order:id,reference,warehouse_id', 'customerReturn:id,reference,receiving_warehouse_id']),
                $request->user(),
            )->findOrFail($record);
            app(ComplaintAuthorization::class)->authorize($request->user(), ComplaintPermission::View, $complaint);
            $case = $complaint;
        } else {
            abort(404);
        }

        return response()->json(['data' => $this->present($request, $kind, $case, true)]);
    }

    public function act(Request $request, string $kind, int $record, string $action): JsonResponse
    {
        try {
            if ($kind === 'claims') {
                $claim = app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query(), $request->user())->findOrFail($record);
                app(SafetClaimAuthorization::class)->authorize($request->user(), SafetClaimPermission::View, $claim);
                $data = $request->validate([
                    'external_reference' => 'nullable|string|max:255',
                    'reason' => 'nullable|string|max:2000',
                    'notes' => 'nullable|string|max:2000',
                    'claimed_amount' => 'nullable|numeric|min:0.01',
                    'approved_amount' => 'nullable|numeric|min:0.01',
                    'reimbursed_amount' => 'nullable|numeric|min:0.01',
                    'paid_date' => 'nullable|date',
                    'assigned_to_user_id' => 'nullable|integer|exists:users,id',
                ]);
                $service = app(SafetClaimService::class);
                $result = match ($action) {
                    'file' => $service->transition($claim, SafetClaimStatus::Filed, $request->user(), $data['external_reference'] ?? null, null, $data['notes'] ?? null),
                    'in_review' => $service->transition($claim, SafetClaimStatus::InReview, $request->user(), null, null, $data['notes'] ?? null),
                    'rejected' => $service->transition($claim, SafetClaimStatus::Rejected, $request->user(), null, $data['reason'] ?? null, $data['notes'] ?? null),
                    'closed' => $service->transition($claim, SafetClaimStatus::Closed, $request->user(), null, null, $data['notes'] ?? null),
                    'not_eligible' => $service->transition($claim, SafetClaimStatus::NotEligible, $request->user(), null, $data['reason'] ?? null, $data['notes'] ?? null),
                    'record_claimed_amount' => $service->recordClaimedAmount($claim, (string) ($data['claimed_amount'] ?? ''), $request->user()),
                    'correct_claimed_amount' => $service->correctClaimedAmount($claim, (string) ($data['claimed_amount'] ?? ''), (string) ($data['reason'] ?? ''), $request->user()),
                    'approve' => $service->recordApproval($claim, (string) ($data['approved_amount'] ?? ''), $request->user(), $data['notes'] ?? null),
                    'paid' => $service->recordPayment($claim, (string) ($data['reimbursed_amount'] ?? ''), $data['paid_date'] ?? null, $request->user(), $data['notes'] ?? null),
                    'assign' => app(SafetClaimAssigneeService::class)->assign($claim, filled($data['assigned_to_user_id'] ?? null) ? (int) $data['assigned_to_user_id'] : null, $request->user()),
                    default => abort(404),
                };
            } elseif ($kind === 'complaints') {
                $complaint = app(ComplaintAuthorization::class)->scopeQuery(Complaint::query(), $request->user())->findOrFail($record);
                app(ComplaintAuthorization::class)->authorize($request->user(), ComplaintPermission::View, $complaint);
                $data = $request->validate([
                    'resolution' => 'nullable|string',
                    'note' => 'nullable|string|max:5000',
                    'assigned_to_user_id' => 'nullable|integer|exists:users,id',
                ]);
                if ($action === 'assign') {
                    $result = app(ServiceCaseAssigneeService::class)->assignComplaint(
                        $complaint,
                        filled($data['assigned_to_user_id'] ?? null) ? (int) $data['assigned_to_user_id'] : null,
                        $request->user(),
                    );
                } else {
                    $status = match ($action) {
                        'in_progress' => ComplaintStatus::InProgress,
                        'resolved' => ComplaintStatus::Resolved,
                        'cancelled' => ComplaintStatus::Cancelled,
                        'closed' => ComplaintStatus::Closed,
                        default => abort(404),
                    };
                    $resolution = filled($data['resolution'] ?? null) ? ComplaintResolution::tryFrom($data['resolution']) : null;
                    abort_if(filled($data['resolution'] ?? null) && $resolution === null, 422, 'Invalid complaint resolution.');
                    $result = app(ComplaintService::class)->transition($complaint, $status, $request->user(), $resolution, $data['note'] ?? null);
                }
            } else {
                abort(404);
            }
        } catch (SafetClaimException|ComplaintException $exception) {
            abort(422, $exception->getMessage());
        }

        return $this->show($request, $kind, $result->id);
    }

    private function present(Request $request, string $kind, SafetClaim|Complaint $case, bool $detail = false): array
    {
        $data = [
            'id' => $case->id,
            'title' => $case->reference,
            'subtitle' => $case->product?->name,
            'meta' => $case->platform?->name,
            'status' => $case->status->value,
        ];
        if (! $detail) {
            return $data;
        }

        if ($kind === 'claims') {
            $fields = [
                'product_sku' => $case->product?->sku,
                'platform' => $case->platform?->name,
                'assigned_to' => $case->assignedTo?->name,
                'claim_reason' => $case->claim_reason,
                'claim_program_name' => $case->claim_program_name,
                'external_claim_reference' => $case->external_claim_reference,
                'quantity' => $case->quantity,
                'filing_due_at' => $case->filing_due_at?->format('Y-m-d'),
                'notes' => $case->notes,
            ];
            if (app(SafetClaimAuthorization::class)->allows($request->user(), SafetClaimPermission::ViewFinancial, $case)) {
                $fields['claimed_amount'] = $case->claimed_amount;
                $fields['approved_amount'] = $case->approved_amount;
                $fields['reimbursed_amount'] = $case->reimbursed_amount;
            }
            $actions = $this->claimActions($request, $case);
        } else {
            $fields = [
                'product_sku' => $case->product?->sku,
                'platform' => $case->platform?->name,
                'assigned_to' => $case->assignedTo?->name,
                'order_reference' => $case->order?->reference,
                'return_reference' => $case->customerReturn?->reference,
                'category' => $case->category->value,
                'description' => $case->description,
                'quantity' => $case->quantity,
                'resolution' => $case->resolution?->value,
                'resolution_note' => $case->resolution_note,
                'opened_at' => $case->opened_at?->format('Y-m-d H:i'),
            ];
            $actions = $this->complaintActions($request, $case);
        }

        return [...$data, 'fields' => $fields, 'actions' => $actions];
    }

    private function claimActions(Request $request, SafetClaim $claim): array
    {
        $auth = app(SafetClaimAuthorization::class);
        $user = $request->user();
        $actions = [];

        if ($claim->status === SafetClaimStatus::NeedsFiling && $auth->allows($user, SafetClaimPermission::File, $claim)) {
            $actions[] = $this->action('file', 'Mark as filed', [
                $this->field('external_reference', 'External claim reference', 'text', true),
                $this->field('notes', 'Notes', 'multiline'),
            ]);
        }
        if ($claim->status === SafetClaimStatus::NeedsFiling && $auth->allows($user, SafetClaimPermission::UpdateStatus, $claim)) {
            $actions[] = $this->action('not_eligible', 'Mark not eligible', [$this->field('reason', 'Reason', 'multiline', true)]);
        }
        if ($claim->status === SafetClaimStatus::Filed && $auth->allows($user, SafetClaimPermission::UpdateStatus, $claim)) {
            $actions[] = $this->action('in_review', 'Move to in review');
        }
        if ($claim->status === SafetClaimStatus::InReview && $auth->allows($user, SafetClaimPermission::UpdateStatus, $claim)) {
            $actions[] = $this->action('rejected', 'Mark rejected', [$this->field('reason', 'Reason', 'multiline', true)]);
        }
        if (in_array($claim->status, [SafetClaimStatus::Paid, SafetClaimStatus::Rejected], true)
            && $auth->allows($user, SafetClaimPermission::Close, $claim)) {
            $actions[] = $this->action('closed', 'Close claim', [$this->field('notes', 'Notes', 'multiline')]);
        }

        if ($auth->allows($user, SafetClaimPermission::UpdateFinancial, $claim)) {
            $actions[] = $claim->claimed_amount === null
                ? $this->action('record_claimed_amount', 'Record claimed amount', [$this->field('claimed_amount', 'Claimed amount', 'number', true)])
                : $this->action('correct_claimed_amount', 'Correct claimed amount', [
                    $this->field('claimed_amount', 'Claimed amount', 'number', true, $claim->claimed_amount),
                    $this->field('reason', 'Audit reason', 'multiline', true),
                ]);
        }
        if ($claim->status === SafetClaimStatus::InReview && $claim->claimed_amount !== null
            && $auth->allows($user, SafetClaimPermission::UpdateStatus, $claim)
            && $auth->allows($user, SafetClaimPermission::UpdateFinancial, $claim)) {
            $actions[] = $this->action('approve', 'Record approval', [
                $this->field('approved_amount', 'Approved amount', 'number', true),
                $this->field('notes', 'Notes', 'multiline'),
            ]);
        }
        if ($claim->status === SafetClaimStatus::Approved
            && $auth->allows($user, SafetClaimPermission::UpdateStatus, $claim)
            && $auth->allows($user, SafetClaimPermission::UpdateFinancial, $claim)) {
            $actions[] = $this->action('paid', 'Record payment', [
                $this->field('reimbursed_amount', 'Reimbursed amount', 'number', true),
                $this->field('paid_date', 'Paid date', 'date', true, today()->format('Y-m-d')),
                $this->field('notes', 'Notes', 'multiline'),
            ]);
        }
        if ($auth->allows($user, SafetClaimPermission::Assign, $claim)) {
            $options = collect(app(SafetClaimAssigneeService::class)->options($claim))
                ->map(fn ($label, $id) => ['value' => $id, 'label' => $label])->values()->all();
            $actions[] = $this->action('assign', 'Assign claim', [
                $this->field('assigned_to_user_id', 'Assigned employee', 'select', false, $claim->assigned_to_user_id, $options),
            ]);
        }

        return $actions;
    }

    private function complaintActions(Request $request, Complaint $complaint): array
    {
        $auth = app(ComplaintAuthorization::class);
        $user = $request->user();
        $actions = [];

        if ($complaint->status === ComplaintStatus::Open && $auth->allows($user, ComplaintPermission::Update, $complaint)) {
            $actions[] = $this->action('in_progress', 'Start work');
        }
        if (! in_array($complaint->status, [ComplaintStatus::Resolved, ComplaintStatus::Closed, ComplaintStatus::Cancelled], true)
            && $auth->allows($user, ComplaintPermission::Resolve, $complaint)) {
            $options = array_map(
                fn (ComplaintResolution $resolution) => ['value' => $resolution->value, 'label' => $resolution->getLabel()],
                ComplaintResolution::cases(),
            );
            $actions[] = $this->action('resolved', 'Resolve complaint', [
                $this->field('resolution', 'Resolution', 'select', true, null, $options),
                $this->field('note', 'Resolution note', 'multiline'),
            ]);
        }
        if (! in_array($complaint->status, [ComplaintStatus::Closed, ComplaintStatus::Cancelled], true)
            && $auth->allows($user, ComplaintPermission::Update, $complaint)) {
            $actions[] = $this->action('cancelled', 'Cancel complaint', [$this->field('note', 'Cancellation reason', 'multiline', true)]);
        }
        if ($complaint->status === ComplaintStatus::Resolved && $auth->allows($user, ComplaintPermission::Update, $complaint)) {
            $actions[] = $this->action('closed', 'Close complaint');
        }
        if ($auth->allows($user, ComplaintPermission::Assign, $complaint)) {
            $options = collect(app(ServiceCaseAssigneeService::class)->complaintOptions($complaint))
                ->map(fn ($label, $id) => ['value' => $id, 'label' => $label])->values()->all();
            $actions[] = $this->action('assign', 'Assign complaint', [
                $this->field('assigned_to_user_id', 'Assigned employee', 'select', false, $complaint->assigned_to_user_id, $options),
            ]);
        }

        return $actions;
    }
}
