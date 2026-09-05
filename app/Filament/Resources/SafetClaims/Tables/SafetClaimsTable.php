<?php

namespace App\Filament\Resources\SafetClaims\Tables;

use App\Enums\SafetClaimPermission;
use App\Enums\SafetClaimStatus;
use App\Exceptions\SafetClaimException;
use App\Models\SafetClaim;
use App\Services\Authorization\SafetClaimAuthorization;
use App\Services\Claims\SafetClaimAssigneeService;
use App\Services\Claims\SafetClaimService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class SafetClaimsTable
{
    public static function configure(Table $table): Table
    {
        $columns = [
            TextColumn::make('reference')->label('Claim Reference')->searchable()->sortable(),
            TextColumn::make('platform.name')->label('Platform'),
            TextColumn::make('order.reference')->label('Order'),
            TextColumn::make('customerReturn.reference')->label('Return'),
            TextColumn::make('product.sku')->label('SKU')->searchable(),
            TextColumn::make('product.name')->label('Product')->searchable()->limit(48)->tooltip(fn (SafetClaim $record): ?string => $record->product?->name),
            TextColumn::make('quantity')->label('Qty'),
            TextColumn::make('claim_reason')->label('Damage Reason'),
            TextColumn::make('damagedStockEvent.occurred_at')->label('QC / Damaged Date')->dateTime('d M Y, h:i A'),
            TextColumn::make('source')->label('Source')->badge(),
            TextColumn::make('status')->badge(),
            TextInputColumn::make('external_claim_reference')->label('External Claim Reference')
                ->disabled(fn (?SafetClaim $record): bool => $record === null || $record->status !== SafetClaimStatus::NeedsFiling || ! auth()->user()->can('safet_claim.file', $record))
                ->updateStateUsing(function (SafetClaim $record, mixed $state): string {
                    try {
                        $updated = app(SafetClaimService::class)->updateExternalReference($record, is_string($state) ? $state : null, auth()->user());
                        Notification::make()->success()->title('External reference saved')->send();

                        return (string) $updated->external_claim_reference;
                    } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
                        self::failed($exception);

                        return (string) $record->fresh()->external_claim_reference;
                    }
                }),
            SelectColumn::make('assigned_to_user_id')->label('Assigned To')->placeholder('Unassigned')->selectablePlaceholder()
                ->options(fn (?SafetClaim $record): array => $record === null ? [] : app(SafetClaimAssigneeService::class)->options($record))
                ->disabled(fn (?SafetClaim $record): bool => $record === null || ! auth()->user()->can('safet_claim.assign', $record))
                ->updateStateUsing(function (SafetClaim $record, mixed $state): ?int {
                    try {
                        $updated = app(SafetClaimAssigneeService::class)->assign($record, filled($state) ? (int) $state : null, auth()->user());
                        Notification::make()->success()->title('Claim assignment updated')->send();

                        return $updated->assigned_to_user_id;
                    } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
                        self::failed($exception);

                        return $record->fresh()->assigned_to_user_id;
                    }
                }),
        ];
        if (self::canViewFinancials()) {
            $columns[] = TextColumn::make('claimed_amount')->label('Claimed')->money('AED', decimalPlaces: 2)->placeholder('—')->toggleable();
            $columns[] = TextColumn::make('approved_amount')->label('Approved')->money('AED', decimalPlaces: 2)->placeholder('—')->toggleable();
            $columns[] = TextColumn::make('reimbursed_amount')->label('Paid Recovery')->money('AED', decimalPlaces: 2)->placeholder('—');
            $columns[] = TextColumn::make('paid_at')->label('Paid Date')->date('d M Y')->placeholder('—')->toggleable();
        }

        return $table->columns($columns)->filters([
            SelectFilter::make('queue')->label('Quick Filter')->options([
                'needs_filing' => 'Needs Filing',
                'in_review' => 'In Review',
                'approved' => 'Approved',
                'rejected' => 'Rejected',
                'paid_closed' => 'Paid / Closed',
            ])->default('needs_filing')->query(function (Builder $query, array $data): Builder {
                return match ($data['value'] ?? null) {
                    'needs_filing' => $query->where('status', SafetClaimStatus::NeedsFiling->value),
                    'in_review' => $query->where('status', SafetClaimStatus::InReview->value),
                    'approved' => $query->where('status', SafetClaimStatus::Approved->value),
                    'rejected' => $query->where('status', SafetClaimStatus::Rejected->value),
                    'paid_closed' => $query->whereIn('status', [SafetClaimStatus::Paid->value, SafetClaimStatus::Closed->value]),
                    default => $query,
                };
            }),
            Filter::make('assigned_to_me')->label('Assigned to Me')->query(fn (Builder $query): Builder => $query->where('assigned_to_user_id', auth()->id())),
            Filter::make('unassigned')->query(fn (Builder $query): Builder => $query->whereNull('assigned_to_user_id')),
            SelectFilter::make('assigned_to_user_id')->label('Assigned To')->options(fn (): array => self::assigneeFilterOptions()),
            SelectFilter::make('marketplace_platform_id')->label('Platform')->options(fn (): array => self::platformFilterOptions()),
        ])->recordActions([
            self::claimedAmountAction(),
            self::fileAction(),
            self::transitionAction('review', 'Move to In Review', SafetClaimStatus::Filed, SafetClaimStatus::InReview),
            self::approvalAction(),
            self::transitionAction('reject', 'Mark Rejected', SafetClaimStatus::InReview, SafetClaimStatus::Rejected, reason: true),
            self::paymentAction(),
            self::transitionAction('closePaid', 'Close Claim', SafetClaimStatus::Paid, SafetClaimStatus::Closed, note: true),
            self::transitionAction('closeRejected', 'Close Claim', SafetClaimStatus::Rejected, SafetClaimStatus::Closed, note: true),
            self::transitionAction('notEligible', 'Mark Not Eligible', SafetClaimStatus::NeedsFiling, SafetClaimStatus::NotEligible, reason: true),
            ViewAction::make(),
        ])->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No Claims found for the selected filters.');
    }

    private static function claimedAmountAction(): Action
    {
        return Action::make('recordClaimedAmount')->label('Record Claimed Amount')
            ->visible(fn (?SafetClaim $record): bool => $record !== null
                && $record->claimed_amount === null
                && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $record))
            ->schema([TextInput::make('claimed_amount')->label('Claimed Amount')->prefix('AED')->numeric()->minValue(0.01)->required()])
            ->action(function (SafetClaim $record, array $data): void {
                try {
                    app(SafetClaimService::class)->recordClaimedAmount($record, (string) $data['claimed_amount'], auth()->user());
                    Notification::make()->success()->title('Claimed amount recorded')->send();
                } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
                    self::failed($exception);
                }
            });
    }

    private static function approvalAction(): Action
    {
        return Action::make('approve')->label('Record Approval')->requiresConfirmation()
            ->visible(fn (?SafetClaim $record): bool => $record !== null
                && $record->status === SafetClaimStatus::InReview
                && $record->claimed_amount !== null
                && auth()->user()->can(SafetClaimPermission::UpdateStatus->value, $record)
                && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $record))
            ->schema([
                TextInput::make('approved_amount')->label('Approved Amount')->prefix('AED')->numeric()->minValue(0.01)->required(),
                Textarea::make('notes')->label('Note')->maxLength(2000),
            ])->action(function (SafetClaim $record, array $data): void {
                try {
                    app(SafetClaimService::class)->recordApproval($record, (string) $data['approved_amount'], auth()->user(), $data['notes'] ?? null);
                    Notification::make()->success()->title('Claim approval recorded')->send();
                } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
                    self::failed($exception);
                }
            });
    }

    private static function paymentAction(): Action
    {
        return Action::make('paid')->label('Record Payment')->requiresConfirmation()
            ->visible(fn (?SafetClaim $record): bool => $record !== null
                && $record->status === SafetClaimStatus::Approved
                && auth()->user()->can(SafetClaimPermission::UpdateStatus->value, $record)
                && auth()->user()->can(SafetClaimPermission::UpdateFinancial->value, $record))
            ->schema([
                TextInput::make('reimbursed_amount')->label('Reimbursed Amount')->prefix('AED')->numeric()->minValue(0.01)->required(),
                DatePicker::make('paid_date')->label('Paid Date')->default(today())->maxDate(today())->required(),
                Textarea::make('notes')->label('Note')->maxLength(2000),
            ])->action(function (SafetClaim $record, array $data): void {
                try {
                    app(SafetClaimService::class)->recordPayment($record, (string) $data['reimbursed_amount'], $data['paid_date'], auth()->user(), $data['notes'] ?? null);
                    Notification::make()->success()->title('Claim payment recorded')->send();
                } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
                    self::failed($exception);
                }
            });
    }

    private static function fileAction(): Action
    {
        return Action::make('file')->label('Mark as Filed')->requiresConfirmation()
            ->visible(fn (?SafetClaim $record): bool => $record !== null && $record->status === SafetClaimStatus::NeedsFiling && auth()->user()->can('safet_claim.file', $record))
            ->schema(fn (?SafetClaim $record): array => [
                TextInput::make('external_reference')->label('External Claim Reference')->default($record?->external_claim_reference)->required()->maxLength(255),
                Textarea::make('notes')->label('Note')->helperText('Optional.')->maxLength(2000),
            ])->action(fn (SafetClaim $record, array $data) => self::transition($record, SafetClaimStatus::Filed, $data['external_reference'] ?? null, null, $data['notes'] ?? null));
    }

    /** @return array<int, string> */
    private static function assigneeFilterOptions(): array
    {
        $query = app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query(), auth()->user());

        return $query->whereNotNull('assigned_to_user_id')
            ->join('users as claim_assignee', 'claim_assignee.id', '=', 'safet_claims.assigned_to_user_id')
            ->distinct()->orderBy('claim_assignee.name')
            ->pluck('claim_assignee.name', 'claim_assignee.id')->all();
    }

    /** @return array<int, string> */
    private static function platformFilterOptions(): array
    {
        $query = app(SafetClaimAuthorization::class)->scopeQuery(SafetClaim::query(), auth()->user());

        return $query->join('marketplace_platforms as claim_platform', 'claim_platform.id', '=', 'safet_claims.marketplace_platform_id')
            ->distinct()->orderBy('claim_platform.name')
            ->pluck('claim_platform.name', 'claim_platform.id')->all();
    }

    private static function transitionAction(string $name, string $label, SafetClaimStatus $from, SafetClaimStatus $to, bool $reason = false, bool $note = false): Action
    {
        $permission = $to === SafetClaimStatus::Closed ? 'safet_claim.close' : 'safet_claim.update_status';
        $action = Action::make($name)->label($label)
            ->visible(fn (?SafetClaim $record): bool => $record !== null && $record->status === $from && auth()->user()->can($permission, $record));
        if ($reason) {
            $action->color('danger')->requiresConfirmation()->schema([
                Textarea::make('reason')->label('Reason')->required()->maxLength(2000),
            ]);
        } elseif ($note) {
            $action->requiresConfirmation()->schema([
                Textarea::make('notes')->label('Note')->helperText('Optional.')->maxLength(2000),
            ]);
        }

        return $action->action(fn (SafetClaim $record, array $data) => self::transition($record, $to, null, $data['reason'] ?? null, $data['notes'] ?? null));
    }

    private static function transition(SafetClaim $record, SafetClaimStatus $to, ?string $external = null, ?string $reason = null, ?string $notes = null): void
    {
        try {
            app(SafetClaimService::class)->transition($record, $to, auth()->user(), $external, $reason, $notes);
            Notification::make()->success()->title('Claim updated')->send();
        } catch (SafetClaimException|ValidationException|AuthorizationException $exception) {
            self::failed($exception);
        }
    }

    private static function failed(SafetClaimException|ValidationException|AuthorizationException $exception): never
    {
        $message = $exception instanceof ValidationException
            ? (collect($exception->errors())->flatten()->first() ?? 'The Claim update is invalid.')
            : ($exception->getMessage() ?: 'You are not authorized to update this Claim.');
        Notification::make()->danger()->title('Cannot update Claim')->body($message)->send();
        throw new Halt;
    }

    private static function canViewFinancials(): bool
    {
        return auth()->check() && app(SafetClaimAuthorization::class)
            ->allows(auth()->user(), SafetClaimPermission::ViewFinancial);
    }
}
