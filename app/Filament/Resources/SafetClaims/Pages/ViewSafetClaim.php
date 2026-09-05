<?php

namespace App\Filament\Resources\SafetClaims\Pages;

use App\Enums\SafetClaimPermission;
use App\Enums\SafetClaimStatus;
use App\Enums\TaskLinkedType;
use App\Exceptions\SafetClaimException;
use App\Filament\Actions\CreateTaskFromSourceAction;
use App\Filament\Actions\OpenChatDiscussionAction;
use App\Filament\Resources\SafetClaims\SafetClaimResource;
use App\Services\Claims\SafetClaimService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class ViewSafetClaim extends ViewRecord
{
    protected static string $resource = SafetClaimResource::class;

    protected function getHeaderActions(): array
    {
        return [
            OpenChatDiscussionAction::make($this->record),
            CreateTaskFromSourceAction::make(TaskLinkedType::SafetClaim, $this->record),
            Action::make('recordClaimedAmount')->label('Record Claimed Amount')
                ->visible(fn (): bool => $this->record->claimed_amount === null
                    && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $this->record))
                ->schema([TextInput::make('claimed_amount')->label('Claimed Amount')->prefix('AED')->numeric()->minValue(0.01)->required()])
                ->action(fn (array $data) => $this->runFinancial(fn () => app(SafetClaimService::class)->recordClaimedAmount($this->record, (string) $data['claimed_amount'], auth()->user()), 'Claimed amount recorded')),
            Action::make('editClaimedAmount')->label('Edit Claimed Amount')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->claimed_amount !== null
                    && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $this->record))
                ->schema([
                    TextInput::make('claimed_amount')->label('Claimed Amount')->prefix('AED')->numeric()->minValue(0.01)->default(fn (): ?string => $this->record->claimed_amount)->required(),
                    Textarea::make('reason')->label('Reason')->helperText('Required for the financial audit trail.')->required()->maxLength(2000),
                ])
                ->action(fn (array $data) => $this->runFinancial(fn () => app(SafetClaimService::class)->correctClaimedAmount($this->record, (string) $data['claimed_amount'], (string) $data['reason'], auth()->user()), 'Claimed amount corrected')),
            Action::make('file')->label('Mark as Filed')->schema([TextInput::make('external_reference')->label('External Claim Reference')->required()->maxLength(255), Textarea::make('notes')->maxLength(2000)])
                ->visible(fn () => $this->record->status === SafetClaimStatus::NeedsFiling && auth()->user()->can('safet_claim.file', $this->record))
                ->action(fn (array $data) => $this->run(SafetClaimStatus::Filed, $data['external_reference'], null, $data['notes'] ?? null)),
            $this->simple('review', 'Move to In Review', SafetClaimStatus::Filed, SafetClaimStatus::InReview),
            Action::make('approve')->label('Record Approval')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === SafetClaimStatus::InReview
                    && $this->record->claimed_amount !== null
                    && auth()->user()->can(SafetClaimPermission::UpdateStatus->value, $this->record)
                    && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $this->record))
                ->schema([TextInput::make('approved_amount')->label('Approved Amount')->prefix('AED')->numeric()->minValue(0.01)->required(), Textarea::make('notes')->maxLength(2000)])
                ->action(fn (array $data) => $this->runFinancial(fn () => app(SafetClaimService::class)->recordApproval($this->record, (string) $data['approved_amount'], auth()->user(), $data['notes'] ?? null), 'Claim approval recorded')),
            Action::make('reject')->label('Mark Rejected')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn () => $this->record->status === SafetClaimStatus::InReview && auth()->user()->can('safet_claim.update_status', $this->record))
                ->action(fn (array $data) => $this->run(SafetClaimStatus::Rejected, null, $data['reason'])),
            Action::make('paid')->label('Record Payment')->requiresConfirmation()
                ->visible(fn (): bool => $this->record->status === SafetClaimStatus::Approved
                    && auth()->user()->can(SafetClaimPermission::UpdateStatus->value, $this->record)
                    && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $this->record))
                ->schema([
                    TextInput::make('reimbursed_amount')->label('Reimbursed Amount')->prefix('AED')->numeric()->minValue(0.01)->required(),
                    DatePicker::make('paid_date')->label('Paid Date')->default(today())->maxDate(today())->required(),
                    Textarea::make('notes')->maxLength(2000),
                ])->action(fn (array $data) => $this->runFinancial(fn () => app(SafetClaimService::class)->recordPayment($this->record, (string) $data['reimbursed_amount'], $data['paid_date'], auth()->user(), $data['notes'] ?? null), 'Claim payment recorded')),
            $this->simple('close', 'Close Claim', SafetClaimStatus::Paid, SafetClaimStatus::Closed, true),
            $this->simple('closeRejected', 'Close Claim', SafetClaimStatus::Rejected, SafetClaimStatus::Closed, true),
            Action::make('notEligible')->label('Mark Not Eligible')->color('danger')->requiresConfirmation()->schema([Textarea::make('reason')->required()->maxLength(2000)])
                ->visible(fn () => $this->record->status === SafetClaimStatus::NeedsFiling && auth()->user()->can('safet_claim.update_status', $this->record))
                ->action(fn (array $data) => $this->run(SafetClaimStatus::NotEligible, null, $data['reason'])),
        ];
    }

    private function simple(string $name, string $label, SafetClaimStatus $from, SafetClaimStatus $to, bool $confirm = false): Action
    {
        $action = Action::make($name)->label($label)->visible(fn () => $this->record->status === $from && auth()->user()->can($to === SafetClaimStatus::Closed ? 'safet_claim.close' : 'safet_claim.update_status', $this->record))->action(fn () => $this->run($to));

        return $confirm ? $action->requiresConfirmation() : $action;
    }

    private function run(SafetClaimStatus $to, ?string $external = null, ?string $reason = null, ?string $notes = null): void
    {
        try {
            $this->record = app(SafetClaimService::class)->transition($this->record, $to, auth()->user(), $external, $reason, $notes);
            Notification::make()->success()->title('Claim updated')->send();
        } catch (SafetClaimException|ValidationException|AuthorizationException $e) {
            $message = $e instanceof ValidationException ? (collect($e->errors())->flatten()->first() ?? 'The Claim update is invalid.') : ($e->getMessage() ?: 'You are not authorized to update this Claim.');
            Notification::make()->danger()->title('Cannot update Claim')->body($message)->send();
            throw new Halt;
        }
    }

    private function runFinancial(callable $operation, string $success): void
    {
        try {
            $this->record = $operation();
            Notification::make()->success()->title($success)->send();
        } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
            $message = $exception instanceof ValidationException
                ? (collect($exception->errors())->flatten()->first() ?? 'The Claim update is invalid.')
                : ($exception->getMessage() ?: 'You are not authorized to update this Claim.');
            Notification::make()->danger()->title('Cannot update Claim')->body($message)->send();
            throw new Halt;
        }
    }
}
