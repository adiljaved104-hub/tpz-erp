<?php

namespace App\Services\Returns;

use App\Enums\CustomerReturnInspectionResult;
use App\Enums\CustomerReturnPermission;
use App\Enums\EmployeeRole;
use App\Filament\Resources\CustomerReturns\CustomerReturnResource;
use App\Models\CustomerReturnEvidence;
use App\Models\CustomerReturnExceptionCase;
use App\Models\CustomerReturnItem;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Authorization\CustomerReturnAuthorization;
use App\Services\Notifications\CriticalAlertDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class CustomerReturnExceptionService
{
    private const IDENTITY_PROBLEMS = ['wrong_item', 'serial_mismatch', 'empty_package'];

    private const DAMAGE_PROBLEMS = ['damaged', 'defective', 'missing_accessories'];

    public function __construct(private readonly ActivityLogger $activity) {}

    /** Called after the immutable evidence file has been stored, inside its transaction. */
    public function record(CustomerReturnEvidence $evidence, CustomerReturnItem $item, User $actor): ?CustomerReturnExceptionCase
    {
        $finding = $evidence->finding;
        $inspections = $item->inspections()->get();
        $passed = (int) $inspections->where('result', CustomerReturnInspectionResult::Sellable)->sum('quantity');
        $failed = (int) $inspections->where('result', CustomerReturnInspectionResult::Damaged)->sum('quantity');
        $identity = in_array($finding->problem_type, self::IDENTITY_PROBLEMS, true);
        $conflict = ($passed > 0 && (in_array($finding->problem_type, self::DAMAGE_PROBLEMS, true) || $identity))
            || ($failed > 0 && $finding->problem_type === 'sellable');
        if (! $identity && ! $conflict) {
            return null;
        }

        $kind = $identity ? 'identity' : 'qc_conflict';
        CustomerReturnItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
        $case = CustomerReturnExceptionCase::query()->where('customer_return_item_id', $item->id)
            ->where('kind', $kind)->whereNull('resolved_at')->lockForUpdate()->first();
        $created = $case === null;
        if ($case === null) {
            $case = CustomerReturnExceptionCase::query()->create([
                'customer_return_item_id' => $item->id, 'finding_id' => $finding->id,
                'evidence_id' => $evidence->id, 'kind' => $kind,
                'open_key' => $item->id.':'.$kind,
                'status' => $conflict ? 'awaiting_review' : 'awaiting_physical_verification',
                'original_qc_result' => $passed && $failed ? 'mixed' : ($passed ? 'pass' : ($failed ? 'fail' : null)),
                'new_problem_type' => $finding->problem_type,
                'affected_quantity' => $passed ?: ($failed ?: $item->return_quantity),
                'protection_status' => $conflict ? 'assessment_pending' : 'not_applicable',
                'hold_key' => (string) Str::uuid(),
                'assigned_to_user_id' => $actor->id,
            ]);
        }
        $case->events()->create([
            'actor_user_id' => $actor->id, 'finding_id' => $finding->id,
            'action' => $created ? 'opened' : 'evidence_added',
            'details' => ['evidence_id' => $evidence->id, 'problem_type' => $finding->problem_type],
            'created_at' => now(),
        ]);
        $this->activity->log($created ? 'customer_return.exception_opened' : 'customer_return.exception_evidence_added', $actor, $item->customerReturn, [
            'case_id' => $case->id, 'evidence_id' => $evidence->id, 'problem_type' => $finding->problem_type,
        ]);
        if ($created) {
            DB::afterCommit(function () use ($case, $actor, $conflict): void {
                if ($conflict) {
                    app(CustomerReturnExceptionHoldService::class)->assess($case->id, $actor);
                }
                $this->notifyReviewers($case->fresh());
            });
        }

        return $case;
    }

    private function notifyReviewers(CustomerReturnExceptionCase $case): void
    {
        try {
            $return = $case->item->customerReturn;
            $authorization = app(CustomerReturnAuthorization::class);
            $recipients = User::query()->whereHas('employee', fn ($query) => $query->where('status', true)
                ->whereIn('role', [EmployeeRole::Owner->value, EmployeeRole::Admin->value]))->with('employee')->get();
            foreach ($recipients as $recipient) {
                if (! $authorization->allows($recipient, CustomerReturnPermission::View, $return)) {
                    continue;
                }
                app(CriticalAlertDispatcher::class)->send($recipient, 'return.exception_review', 'case:'.$case->id, [
                    'category' => 'return', 'event' => 'return.exception_review',
                    'title' => 'Customer Return Needs Review',
                    'message' => $return->reference.' has new evidence requiring Owner/Admin review.',
                    'reference' => $return->reference, 'status' => $case->status,
                    'target_type' => 'customer_return', 'target_id' => $return->id,
                ], 'Customer Return Needs Review — '.$return->reference,
                    CustomerReturnResource::getUrl('view', ['record' => $return]));
            }
        } catch (Throwable $exception) {
            // Evidence and the durable review case already committed. A notification
            // transport failure must not turn a successful upload into an apparent failure.
            Log::warning('Return exception notification is pending.', [
                'case_id' => $case->id, 'exception' => $exception::class,
            ]);
        }
    }
}
