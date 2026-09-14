<?php

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Enums\ComplaintPermission;
use App\Enums\SafetClaimPermission;
use App\Models\Complaint;
use App\Models\SafetClaim;
use App\Services\Authorization\ComplaintAuthorization;
use App\Services\Authorization\SafetClaimAuthorization;
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
            $query = $auth->scopeQuery(SafetClaim::query()->with(['product:id,name', 'platform:id,name']), $user);
            if ($request->input('filter') === 'open') {
                $query->whereNotIn('status', ['rejected', 'closed', 'not_eligible']);
            }
            $search = ['reference', 'claim_reason', 'external_claim_reference'];
        } elseif ($kind === 'complaints') {
            $auth = app(ComplaintAuthorization::class);
            $auth->authorize($user, ComplaintPermission::View);
            $query = $auth->scopeQuery(Complaint::query()->with(['product:id,name', 'platform:id,name']), $user);
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
            $claim = app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query()->with(['product:id,name', 'platform:id,name']), $request->user())->findOrFail($record);
            app(SafetClaimAuthorization::class)->authorize($request->user(), SafetClaimPermission::View, $claim);
            $case = $claim;
        } elseif ($kind === 'complaints') {
            $complaint = app(ComplaintAuthorization::class)->scopeQuery(Complaint::query()->with(['product:id,name', 'platform:id,name']), $request->user())->findOrFail($record);
            app(ComplaintAuthorization::class)->authorize($request->user(), ComplaintPermission::View, $complaint);
            $case = $complaint;
        } else {
            abort(404);
        }

        return response()->json(['data' => $this->present($request, $kind, $case, true)]);
    }

    private function present(Request $request, string $kind, $case, bool $detail = false): array
    {
        $data = ['id' => $case->id, 'title' => $case->reference, 'subtitle' => $case->product?->name,
            'meta' => $case->platform?->name, 'status' => $case->status->value];
        if (! $detail) {
            return $data;
        }
        if ($kind === 'claims') {
            $fields = ['claim_reason' => $case->claim_reason, 'claim_program_name' => $case->claim_program_name,
                'external_claim_reference' => $case->external_claim_reference, 'quantity' => $case->quantity,
                'filing_due_at' => $case->filing_due_at?->format('Y-m-d'), 'notes' => $case->notes];
            if (app(SafetClaimAuthorization::class)->allows($request->user(), SafetClaimPermission::ViewFinancial, $case)) {
                $fields['claimed_amount'] = $case->claimed_amount;
                $fields['approved_amount'] = $case->approved_amount;
                $fields['reimbursed_amount'] = $case->reimbursed_amount;
            }
        } else {
            $fields = ['category' => $case->category->value, 'description' => $case->description,
                'quantity' => $case->quantity, 'resolution' => $case->resolution?->value,
                'resolution_note' => $case->resolution_note, 'opened_at' => $case->opened_at?->format('Y-m-d H:i')];
        }

        return [...$data, 'fields' => $fields, 'actions' => []];
    }
}
