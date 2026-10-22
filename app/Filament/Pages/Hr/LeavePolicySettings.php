<?php

namespace App\Filament\Pages\Hr;

use App\Enums\HrPermission;
use App\Models\LeavePolicy;
use App\Models\User;
use App\Services\Authorization\HrAuthorization;
use App\Services\Hr\HistoricalLeaveActivationService;
use App\Services\Hr\LeavePolicyVersioningService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

class LeavePolicySettings extends Page
{
    protected string $view = 'filament.pages.hr.leave-policy-settings';

    protected static ?string $slug = 'administration/hr/leave-policy-settings';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 31;

    protected static ?string $navigationLabel = 'Leave Policy Settings';

    protected static ?string $title = 'Annual Leave Settings';

    public string $annualEntitlementDays = '12.00';

    public bool $carryForwardEnabled = false;

    public string $maximumCarryForwardDays = '0.00';

    public ?int $carryForwardExpiryMonths = null;

    public string $effectiveDate = '';

    public string $description = '';

    public string $reason = '';

    public string $cycleTransitionDecision = '';

    public string $policyStatus = 'draft';

    public bool $confirmPolicyActivation = false;

    public bool $confirmDraftActivation = false;

    public ?array $impact = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(HrAuthorization::class)->allows($user, HrPermission::LeaveManagePolicy);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $policy = $this->currentPolicy;
        $this->annualEntitlementDays = (string) $policy->annual_leave_entitlement_days;
        $this->carryForwardEnabled = (bool) $policy->carry_forward_enabled;
        $this->maximumCarryForwardDays = (string) $policy->maximum_carry_forward_days;
        $this->carryForwardExpiryMonths = $policy->carry_forward_expiry_months;
        $this->effectiveDate = now()->addDay()->toDateString();
        $this->description = (string) $policy->policy_description;
        $this->refreshImpact();
    }

    public function updatedEffectiveDate(): void
    {
        $this->refreshImpact();
    }

    public function updatedCarryForwardEnabled(bool $enabled): void
    {
        if (! $enabled) {
            $this->maximumCarryForwardDays = '0.00';
            $this->carryForwardExpiryMonths = null;
        }
    }

    public function updatedPolicyStatus(string $status): void
    {
        if ($status !== 'active') {
            $this->confirmPolicyActivation = false;
        }
    }

    public function refreshImpact(): void
    {
        if ($this->effectiveDate === '') {
            $this->impact = null;

            return;
        }
        try {
            $this->impact = app(LeavePolicyVersioningService::class)->transitionImpact($this->effectiveDate, $this->user());
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException) {
            $this->impact = ['employees_in_mid_cycle' => 0, 'unresolved_leap_day_rule' => 1, 'effective_date' => $this->effectiveDate];
        }
    }

    public function savePolicyVersion(): void
    {
        try {
            app(LeavePolicyVersioningService::class)->createVersion([
                'annual_entitlement_days' => $this->annualEntitlementDays,
                'carry_forward_enabled' => $this->carryForwardEnabled,
                'maximum_carry_forward_days' => $this->maximumCarryForwardDays,
                'carry_forward_expiry_months' => $this->carryForwardExpiryMonths,
                'effective_date' => $this->effectiveDate,
                'description' => $this->description,
                'reason' => $this->reason,
                'cycle_transition_decision' => $this->cycleTransitionDecision !== '' ? $this->cycleTransitionDecision : null,
                'status' => $this->policyStatus,
                'activation_confirmed' => $this->confirmPolicyActivation,
            ], $this->user());
            $savedStatus = $this->policyStatus;
            $this->reset('reason', 'cycleTransitionDecision', 'confirmPolicyActivation');
            $this->policyStatus = 'draft';
            Notification::make()->success()->title($savedStatus === 'active' ? 'Active policy replacement scheduled' : 'Leave policy Draft saved')->send();
            $this->redirect(static::getUrl(), navigate: true);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $map = [
                    'annual_entitlement_days' => 'annualEntitlementDays', 'carry_forward_enabled' => 'carryForwardEnabled',
                    'maximum_carry_forward_days' => 'maximumCarryForwardDays', 'carry_forward_expiry_months' => 'carryForwardExpiryMonths',
                    'effective_date' => 'effectiveDate', 'cycle_transition_decision' => 'cycleTransitionDecision',
                    'description' => 'description', 'reason' => 'reason',
                    'status' => 'policyStatus', 'activation_confirmed' => 'confirmPolicyActivation',
                ];
                $this->addError($map[$field] ?? 'reason', $messages[0]);
            }
        }
    }

    public function activatePolicyVersion(int $policyId): void
    {
        try {
            app(LeavePolicyVersioningService::class)->activateDraft($policyId, $this->user(), $this->confirmDraftActivation);
            $this->confirmDraftActivation = false;
            Notification::make()->success()->title('Draft policy replacement activated')->send();
            $this->redirect(static::getUrl(), navigate: true);
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $map = [
                    'effective_date' => 'effectiveDate', 'activation_confirmed' => 'confirmDraftActivation',
                    'cycle_transition_decision' => 'cycleTransitionDecision',
                ];
                $this->addError($map[$field] ?? 'confirmDraftActivation', $messages[0]);
            }
        }
    }

    #[Computed]
    public function currentPolicy(): LeavePolicy
    {
        $today = now()->toDateString();

        return LeavePolicy::query()->where('status', true)->whereDate('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today))
            ->latest('effective_from')->firstOrFail();
    }

    #[Computed]
    public function annualWorkflowActive(): bool
    {
        return app(HistoricalLeaveActivationService::class)->isActive();
    }

    #[Computed]
    public function policyVersions()
    {
        return LeavePolicy::query()->with('events.actor')->orderByDesc('effective_from')->paginate(15);
    }

    private function user(): User
    {
        abort_unless(static::canAccess(), 403);

        return auth()->user();
    }
}
